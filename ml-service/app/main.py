"""
SmartHog ML Service — FastAPI application for training and prediction.

Three models:
1. Feed Requirement Regression — predicts optimal feed amount per pig
2. Growth Stage Classification — classifies pigs into growth stages
3. Farm Trend Analyzer — predicts weight gain and feed consumption trends
"""
import os
import pickle
import json
import logging
from pathlib import Path
from datetime import datetime, timezone
from typing import Optional

import numpy as np
import pandas as pd
from fastapi import FastAPI, HTTPException, Depends
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("smarthog-ml")

app = FastAPI(
    title="SmartHog ML Service",
    description="ML training and prediction service for IoT-based pig feeding system",
    version="1.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ─── Configuration ────────────────────────────────────────────────────────────

MODEL_DIR = Path(os.getenv("MODEL_DIR", "./saved_models"))
MODEL_DIR.mkdir(parents=True, exist_ok=True)

API_KEY = os.getenv("API_KEY", "")


# ─── Pydantic Models ──────────────────────────────────────────────────────────

class PigData(BaseModel):
    id: int
    pig_age_days: int
    avg_weight_kg: float
    feeding_frequency: int
    time1: str = "6:00 am"
    time2: str = "12:00 pm"
    time3: str = "6:00 pm"
    growth_stage: str = ""
    schedule_type: str = "everyday"


class PredictionRequest(BaseModel):
    pigs: list[PigData]


class TrainingRecord(BaseModel):
    pig_age_days: int
    avg_weight_kg: float
    feed_amount_kg: float
    weight_gain_kg: float
    feeding_frequency: int
    growth_stage: str
    feed_conversion_ratio: float = 0.0


class TrainingRequest(BaseModel):
    records: list[TrainingRecord]
    model_type: str = Field(default="feed_regression", pattern="^(feed_regression|growth_classification|trend_analysis)$")


class PredictionResult(BaseModel):
    pig_id: int
    pig_age_days: int
    avg_weight_kg: float
    recommended_feed_kg: float
    predicted_weight_kg: Optional[float] = None
    predicted_growth_stage: str
    confidence_score: float
    confidence_level: str
    warnings: list[str] = []


class PredictionResponse(BaseModel):
    success: bool
    model_version: str
    model_type: str
    predictions: list[PredictionResult]
    summary: dict


class TrainingResponse(BaseModel):
    success: bool
    model_type: str
    model_version: str
    samples_used: int
    metrics: dict


class HealthResponse(BaseModel):
    status: str
    models_loaded: dict
    timestamp: str


# ─── Model Manager ────────────────────────────────────────────────────────────

MODEL_REGISTRY = {
    "feed_regression": {
        "file": "feed_regression_v1.pkl",
        "version": "1.0.0",
        "description": "Ridge regression for feed requirement prediction",
        "trained": False,
        "model": None,
    },
    "growth_classification": {
        "file": "growth_classification_v1.pkl",
        "version": "1.0.0",
        "description": "DecisionTreeClassifier for growth stage",
        "trained": False,
        "model": None,
    },
    "trend_analysis": {
        "file": "trend_analysis_v1.pkl",
        "version": "1.0.0",
        "description": "LinearRegression for weight/feed trends",
        "trained": False,
        "model": None,
    },
}

SEED_DATA_LOADED = False


def load_model(model_type: str):
    """Load a trained model from disk."""
    info = MODEL_REGISTRY[model_type]
    path = MODEL_DIR / info["file"]
    if path.exists():
        with open(path, "rb") as f:
            info["model"] = pickle.load(f)
        info["trained"] = True
        logger.info(f"Loaded {model_type} model from {path}")
    return info["model"]


def save_model(model_type: str, model):
    """Persist a trained model to disk."""
    info = MODEL_REGISTRY[model_type]
    path = MODEL_DIR / info["file"]
    with open(path, "wb") as f:
        pickle.dump(model, f)
    info["model"] = model
    info["trained"] = True
    logger.info(f"Saved {model_type} model to {path}")


def try_load_all_models():
    """Attempt to load all saved models on startup."""
    for model_type in MODEL_REGISTRY:
        load_model(model_type)


# Load models on startup
try_load_all_models()


# ─── Rule-Based Baseline (Cold Start) ─────────────────────────────────────────

# Industry-standard feed requirements by growth stage (kg feed per kg body weight per day)
FEED_RATES = {
    "hog pre-starter": {"rate": 0.05, "fcr": 1.5, "daily_gain": 0.25},
    "hog starter": {"rate": 0.045, "fcr": 2.0, "daily_gain": 0.50},
    "hog grower": {"rate": 0.04, "fcr": 2.8, "daily_gain": 0.70},
    "hog finisher": {"rate": 0.035, "fcr": 3.5, "daily_gain": 0.80},
}

GROWTH_STAGE_THRESHOLDS = [
    (50, "hog pre-starter"),
    (80, "hog starter"),
    (130, "hog grower"),
    (float("inf"), "hog finisher"),
]


def classify_growth_stage(age_days: int) -> str:
    """Rule-based growth stage classification."""
    for threshold, stage in GROWTH_STAGE_THRESHOLDS:
        if age_days <= threshold:
            return stage
    return "hog finisher"


def predict_feed_baseline(age_days: int, weight_kg: float, frequency: int) -> dict:
    """Rule-based feed requirement prediction (cold start, no ML trained)."""
    stage = classify_growth_stage(age_days)
    rate_info = FEED_RATES.get(stage, FEED_RATES["hog grower"])

    daily_feed = weight_kg * rate_info["rate"]
    per_feeding = round(daily_feed / frequency, 2)
    predicted_daily_gain = rate_info["daily_gain"]
    fcr = rate_info["fcr"]

    return {
        "recommended_feed_kg": per_feeding,
        "predicted_weight_kg": round(weight_kg + predicted_daily_gain * 30, 2),
        "predicted_growth_stage": stage,
        "daily_feed_total_kg": round(daily_feed, 2),
        "feed_conversion_ratio": fcr,
        "source": "rule_based",
    }


# ─── Training Data Seeder ────────────────────────────────────────────────────

INDUSTRY_SEED_DATA = [
    # age_days, weight_kg, feed_kg, weight_gain_kg, frequency, stage, fcr
    (21, 6.0, 0.30, 0.20, 3, "hog pre-starter", 1.5),
    (35, 10.0, 0.45, 0.30, 3, "hog pre-starter", 1.5),
    (50, 15.0, 0.68, 0.40, 3, "hog starter", 1.7),
    (60, 20.0, 0.85, 0.45, 3, "hog starter", 1.9),
    (80, 30.0, 1.20, 0.60, 3, "hog starter", 2.0),
    (90, 38.0, 1.45, 0.65, 3, "hog grower", 2.2),
    (100, 48.0, 1.80, 0.70, 3, "hog grower", 2.5),
    (110, 58.0, 2.10, 0.72, 3, "hog grower", 2.8),
    (120, 68.0, 2.35, 0.70, 3, "hog grower", 3.0),
    (130, 76.0, 2.55, 0.68, 3, "hog grower", 3.2),
    (140, 84.0, 2.75, 0.65, 3, "hog finisher", 3.4),
    (150, 90.0, 2.90, 0.60, 3, "hog finisher", 3.6),
    (160, 96.0, 3.05, 0.55, 3, "hog finisher", 3.8),
    (170, 101.0, 3.20, 0.50, 3, "hog finisher", 4.0),
    (180, 105.0, 3.30, 0.45, 3, "hog finisher", 4.2),
]


def seed_training_data() -> pd.DataFrame:
    """Seed initial training data from industry standards."""
    records = []
    for age, weight, feed, gain, freq, stage, fcr in INDUSTRY_SEED_DATA:
        records.append({
            "pig_age_days": age,
            "avg_weight_kg": weight,
            "feed_amount_kg": feed,
            "weight_gain_kg": gain,
            "feeding_frequency": freq,
            "growth_stage": stage,
            "feed_conversion_ratio": fcr,
        })
    return pd.DataFrame(records)


# ─── Auth ─────────────────────────────────────────────────────────────────────

def verify_api_key(api_key: str = None):
    if API_KEY and api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid API key")


# ─── Endpoints ────────────────────────────────────────────────────────────────

@app.get("/health", response_model=HealthResponse)
async def health_check():
    return HealthResponse(
        status="ok",
        models_loaded={
            name: info["trained"] for name, info in MODEL_REGISTRY.items()
        },
        timestamp=datetime.now(timezone.utc).isoformat(),
    )


@app.post("/train", response_model=TrainingResponse)
async def train_model(request: TrainingRequest, api_key: str = Depends(verify_api_key)):
    """Train or retrain a model with provided data."""
    from sklearn.linear_model import Ridge, LinearRegression
    from sklearn.tree import DecisionTreeClassifier
    from sklearn.model_selection import cross_val_score

    if not request.records:
        raise HTTPException(status_code=402, detail="No training records provided")

    try:
        df = pd.DataFrame([r.model_dump() for r in request.records])
        samples = len(df)

        if request.model_type == "feed_regression":
            # Predict feed_amount_kg from age, weight, frequency
            X = df[["pig_age_days", "avg_weight_kg", "feeding_frequency"]].values
            y = df["feed_amount_kg"].values

            model = Ridge(alpha=1.0)
            model.fit(X, y)
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring="r2")
            r2 = float(np.mean(scores))

            save_model("feed_regression", model)

            return TrainingResponse(
                success=True,
                model_type="feed_regression",
                model_version=MODEL_REGISTRY["feed_regression"]["version"],
                samples_used=samples,
                metrics={"r2_score": round(r2, 4), "mae": round(float(np.mean(np.abs(y - model.predict(X)))), 4)},
            )

        elif request.model_type == "growth_classification":
            # Predict growth_stage from age, weight
            from sklearn.preprocessing import LabelEncoder

            X = df[["pig_age_days", "avg_weight_kg"]].values
            le = LabelEncoder()
            y = le.fit_transform(df["growth_stage"].values)

            model = DecisionTreeClassifier(max_depth=5, random_state=42)
            model.fit(X, y)
            model.label_encoder_ = le
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring="accuracy")
            acc = float(np.mean(scores))

            save_model("growth_classification", model)

            return TrainingResponse(
                success=True,
                model_type="growth_classification",
                model_version=MODEL_REGISTRY["growth_classification"]["version"],
                samples_used=samples,
                metrics={"accuracy": round(acc, 4)},
            )

        elif request.model_type == "trend_analysis":
            # Predict weight_gain_kg from age, weight, feed_amount, frequency
            X = df[["pig_age_days", "avg_weight_kg", "feed_amount_kg", "feeding_frequency"]].values
            y = df["weight_gain_kg"].values

            model = LinearRegression()
            model.fit(X, y)
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring="r2")
            r2 = float(np.mean(scores))

            save_model("trend_analysis", model)

            return TrainingResponse(
                success=True,
                model_type="trend_analysis",
                model_version=MODEL_REGISTRY["trend_analysis"]["version"],
                samples_used=samples,
                metrics={"r2_score": round(r2, 4)},
            )

    except Exception as e:
        logger.error(f"Training failed: {e}")
        raise HTTPException(status_code=500, detail=f"Training failed: {str(e)}")


@app.post("/predict", response_model=PredictionResponse)
async def predict(request: PredictionRequest, api_key: str = Depends(verify_api_key)):
    """Generate feed predictions for a batch of pigs."""
    predictions = []
    total_feed = 0.0

    ml_available = MODEL_REGISTRY["feed_regression"]["trained"]

    for pig in request.pigs:
        age = pig.pig_age_days
        weight = pig.avg_weight_kg
        freq = pig.feeding_frequency

        if ml_available:
            try:
                model = MODEL_REGISTRY["feed_regression"]["model"]
                X = np.array([[age, weight, freq]])
                recommended = float(model.predict(X)[0])
                source = "ml_model"
            except Exception:
                baseline = predict_feed_baseline(age, weight, freq)
                recommended = baseline["recommended_feed_kg"]
                source = "rule_based_fallback"
        else:
            baseline = predict_feed_baseline(age, weight, freq)
            recommended = baseline["recommended_feed_kg"]
            source = "rule_based"

        stage = classify_growth_stage(age)
        rate_info = FEED_RATES.get(stage, FEED_RATES["hog grower"])

        warnings = []
        if recommended > weight * 0.06:
            warnings.append(f"Feed amount ({recommended}kg) exceeds 6% of body weight — verify pig health")
        if freq < 2:
            warnings.append("Feeding frequency below recommended minimum of 2 times/day")
        if age > 150 and weight < 80:
            warnings.append("Pig appears underweight for age — consider health check")

        pred = PredictionResult(
            pig_id=pig.id,
            pig_age_days=age,
            avg_weight_kg=weight,
            recommended_feed_kg=round(max(recommended, 0.1), 2),
            predicted_weight_kg=round(weight + rate_info["daily_gain"] * 30, 2),
            predicted_growth_stage=stage,
            confidence_score=0.85 if ml_available else 0.60,
            confidence_level="high" if ml_available else "medium",
            warnings=warnings,
        )
        predictions.append(pred)
        total_feed += recommended

    return PredictionResponse(
        success=True,
        model_version=MODEL_REGISTRY["feed_regression"]["version"],
        model_type="feed_regression",
        predictions=predictions,
        summary={
            "total_pigs": len(predictions),
            "total_recommended_feed_kg": round(total_feed, 2),
            "average_feed_per_pig_kg": round(total_feed / len(predictions), 2) if predictions else 0,
            "model_source": "ml" if ml_available else "rule_based",
        },
    )


@app.post("/seed")
async def seed_models():
    """Train all models with industry seed data (cold start)."""
    df = seed_training_data()
    results = []

    # Train feed regression
    feed_records = [
        TrainingRecord(
            pig_age_days=r["pig_age_days"],
            avg_weight_kg=r["avg_weight_kg"],
            feed_amount_kg=r["feed_amount_kg"],
            weight_gain_kg=r["weight_gain_kg"],
            feeding_frequency=r["feeding_frequency"],
            growth_stage=r["growth_stage"],
            feed_conversion_ratio=r["feed_conversion_ratio"],
        )
        for _, r in df.iterrows()
    ]
    train_req = TrainingRequest(records=feed_records, model_type="feed_regression")
    results.append(await train_model(train_req))

    train_req2 = TrainingRequest(records=feed_records, model_type="growth_classification")
    results.append(await train_model(train_req2))

    train_req3 = TrainingRequest(records=feed_records, model_type="trend_analysis")
    results.append(await train_model(train_req3))

    global SEED_DATA_LOADED
    SEED_DATA_LOADED = True

    return {
        "success": True,
        "message": "All models seeded with industry standard data and trained",
        "results": [r.model_dump() for r in results],
    }
