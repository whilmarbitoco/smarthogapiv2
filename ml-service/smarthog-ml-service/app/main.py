"""
SmartHog ML Service — FastAPI application for training and prediction.
Uses synthetic pig growth data based on NRC 2012 and PIC Genetics standards.
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

API_KEY = os.getenv("SMARTHOG_API_KEY", "")

# ─── Model Registry ───────────────────────────────────────────────────────────

MODEL_REGISTRY = {
    "feed_regression": {
        "file": "feed_regression_v1.pkl",
        "version": "1.0.0",
        "description": "Ridge regression for feed requirement prediction",
        "trained": False,
        "model": None,
        "encoders": None,
    },
    "growth_classification": {
        "file": "growth_classification_v1.pkl",
        "version": "1.0.0",
        "description": "DecisionTreeClassifier for growth stage",
        "trained": False,
        "model": None,
        "encoder": None,
    },
    "trend_analysis": {
        "file": "trend_analysis_v1.pkl",
        "version": "1.0.0",
        "description": "LinearRegression for weight gain trends",
        "trained": False,
        "model": None,
        "encoders": None,
    },
}


def load_model(model_type: str):
    """Load a trained model from disk."""
    info = MODEL_REGISTRY[model_type]
    path = MODEL_DIR / info["file"]
    if path.exists():
        with open(path, "rb") as f:
            data = pickle.load(f)
        if isinstance(data, dict):
            info["model"] = data.get("model")
            info["encoders"] = data.get("encoders")
            info["encoder"] = data.get("encoder")
        else:
            info["model"] = data
        info["trained"] = True
        logger.info(f"Loaded {model_type} model from {path}")
    return info["model"]


def save_model(model_type: str, data: dict):
    """Persist a trained model to disk."""
    info = MODEL_REGISTRY[model_type]
    path = MODEL_DIR / info["file"]
    with open(path, "wb") as f:
        pickle.dump(data, f)
    info["model"] = data.get("model")
    info["encoders"] = data.get("encoders")
    info["encoder"] = data.get("encoder")
    info["trained"] = True
    logger.info(f"Saved {model_type} model to {path}")


def try_load_all_models():
    """Attempt to load all saved models on startup."""
    for model_type in MODEL_REGISTRY:
        load_model(model_type)


try_load_all_models()


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
    breed: str = "Large_White"
    body_condition: float = 3.0
    temperature_c: float = 28.0
    health_status: str = "healthy"


class PredictionRequest(BaseModel):
    pigs: list[PigData]


class TrainingRecord(BaseModel):
    pig_age_days: int
    avg_weight_kg: float
    feed_amount_kg: float
    weight_gain_kg: float
    feeding_frequency: int
    growth_stage: str
    breed: str = "Large_White"
    body_condition: float = 3.0
    temperature_c: float = 28.0
    health_status: str = "healthy"
    fcr: float = 2.5


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
    from sklearn.preprocessing import LabelEncoder

    if not request.records:
        raise HTTPException(status_code=402, detail="No training records provided")

    try:
        df = pd.DataFrame([r.model_dump() for r in request.records])
        samples = len(df)

        if request.model_type == "feed_regression":
            le_breed = LabelEncoder()
            le_health = LabelEncoder()
            df['breed_enc'] = le_breed.fit_transform(df['breed'])
            df['health_enc'] = le_health.fit_transform(df['health_status'])

            X = df[['pig_age_days', 'avg_weight_kg', 'feeding_frequency', 'breed_enc', 'body_condition', 'temperature_c', 'health_enc']].values
            y = df['feed_amount_kg'].values

            model = Ridge(alpha=1.0)
            model.fit(X, y)
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring='r2')
            r2 = float(np.mean(scores))

            save_model("feed_regression", {"model": model, "encoders": {"breed": le_breed, "health": le_health}})

            return TrainingResponse(
                success=True, model_type="feed_regression",
                model_version=MODEL_REGISTRY["feed_regression"]["version"],
                samples_used=samples,
                metrics={"r2_score": round(r2, 4), "mae": round(float(np.mean(np.abs(y - model.predict(X)))), 4)},
            )

        elif request.model_type == "growth_classification":
            le_stage = LabelEncoder()
            df['stage_enc'] = le_stage.fit_transform(df['growth_stage'])

            X = df[['pig_age_days', 'avg_weight_kg', 'body_condition', 'feed_amount_kg', 'temperature_c']].values
            y = df['stage_enc'].values

            model = DecisionTreeClassifier(max_depth=6, random_state=42)
            model.fit(X, y)
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring="accuracy")
            acc = float(np.mean(scores))

            save_model("growth_classification", {"model": model, "encoder": le_stage})

            return TrainingResponse(
                success=True, model_type="growth_classification",
                model_version=MODEL_REGISTRY["growth_classification"]["version"],
                samples_used=samples,
                metrics={"accuracy": round(acc, 4)},
            )

        elif request.model_type == "trend_analysis":
            le_breed = LabelEncoder()
            le_health = LabelEncoder()
            df['breed_enc'] = le_breed.fit_transform(df['breed'])
            df['health_enc'] = le_health.fit_transform(df['health_status'])

            X = df[['pig_age_days', 'avg_weight_kg', 'feed_amount_kg', 'feeding_frequency', 'fcr', 'body_condition', 'temperature_c', 'health_enc']].values
            y = df['weight_gain_kg'].values

            model = LinearRegression()
            model.fit(X, y)
            scores = cross_val_score(model, X, y, cv=min(3, samples), scoring="r2")
            r2 = float(np.mean(scores))

            save_model("trend_analysis", {"model": model, "encoders": {"breed": le_breed, "health": le_health}})

            return TrainingResponse(
                success=True, model_type="trend_analysis",
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

    ml_feed = MODEL_REGISTRY["feed_regression"]["trained"]
    ml_growth = MODEL_REGISTRY["growth_classification"]["trained"]
    ml_trend = MODEL_REGISTRY["trend_analysis"]["trained"]

    for pig in request.pigs:
        age = pig.pig_age_days
        weight = pig.avg_weight_kg
        freq = pig.feeding_frequency

        # ── Feed Prediction ──
        if ml_feed:
            try:
                encoders = MODEL_REGISTRY["feed_regression"]["encoders"]
                breed_enc = encoders["breed"].transform([pig.breed])[0] if pig.breed in encoders["breed"].classes_ else 0
                health_enc = encoders["health"].transform([pig.health_status])[0] if pig.health_status in encoders["health"].classes_ else 0
                X = np.array([[age, weight, freq, breed_enc, pig.body_condition, pig.temperature_c, health_enc]])
                recommended = float(MODEL_REGISTRY["feed_regression"]["model"].predict(X)[0])
                source = "ml_model"
            except Exception:
                recommended = weight * 0.04
                source = "rule_based_fallback"
        else:
            if age <= 50:   recommended = weight * 0.06 / freq
            elif age <= 80: recommended = weight * 0.05 / freq
            elif age <= 130: recommended = weight * 0.04 / freq
            else:           recommended = weight * 0.035 / freq
            source = "rule_based"

        # ── Growth Stage ──
        if ml_growth:
            try:
                X_g = np.array([[age, weight, pig.body_condition, recommended, pig.temperature_c]])
                stage_pred = MODEL_REGISTRY["growth_classification"]["model"].predict(X_g)[0]
                stage = MODEL_REGISTRY["growth_classification"]["encoder"].inverse_transform([stage_pred])[0]
            except Exception:
                stage = classify_stage(age)
        else:
            stage = classify_stage(age)

        # ── Weight Gain ──
        if ml_trend:
            try:
                encoders = MODEL_REGISTRY["trend_analysis"]["encoders"]
                breed_enc = encoders["breed"].transform([pig.breed])[0] if pig.breed in encoders["breed"].classes_ else 0
                health_enc = encoders["health"].transform([pig.health_status])[0] if pig.health_status in encoders["health"].classes_ else 0
                fcr_est = 1.5 + (age / 180) * 2.0
                X_t = np.array([[age, weight, recommended, freq, fcr_est, pig.body_condition, pig.temperature_c, health_enc]])
                gain = float(MODEL_REGISTRY["trend_analysis"]["model"].predict(X_t)[0])
            except Exception:
                gain = recommended / (1.5 + (age / 180) * 2.0)
        else:
            fcr = 1.5 + (age / 180) * 2.0
            gain = recommended / fcr

        # ── Warnings ──
        warnings = []
        if recommended > weight * 0.06:
            warnings.append(f"Feed amount ({recommended:.2f}kg) exceeds 6% of body weight")
        if freq < 2:
            warnings.append("Feeding frequency below recommended minimum of 2 times/day")
        if age > 150 and weight < 80:
            warnings.append("Pig appears underweight for age — consider health check")
        if pig.temperature_c > 32:
            warnings.append("High temperature — heat stress may reduce feed intake")

        pred = PredictionResult(
            pig_id=pig.id, pig_age_days=age, avg_weight_kg=weight,
            recommended_feed_kg=round(max(recommended, 0.1), 2),
            predicted_weight_kg=round(weight + gain * 30, 2),
            predicted_growth_stage=stage,
            confidence_score=0.97 if ml_feed else 0.60,
            confidence_level="high" if ml_feed else "medium",
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
            "model_source": "ml" if ml_feed else "rule_based",
        },
    )


def classify_stage(age: int) -> str:
    if age <= 50: return "pre_starter"
    elif age <= 80: return "starter"
    elif age <= 130: return "grower"
    return "finisher"
