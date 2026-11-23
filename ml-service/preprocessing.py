"""Feature preprocessing for the K-Means clustering microservice."""

import numpy as np
import pandas as pd
from sklearn.preprocessing import MinMaxScaler

from config import FEATURES, LOG_FEATURES


def to_feature_frame(items: list[dict]) -> pd.DataFrame:
    """Builds a DataFrame of the model's 2 feature columns, in order, from
    the raw item dicts posted by the CI4 backend. Extra fields (unit_cost,
    lead_time_days) are ignored."""
    return pd.DataFrame(items)[FEATURES].astype(float)


def _log_columns() -> list[int]:
    return [FEATURES.index(f) for f in LOG_FEATURES]


def normalize_features(frame: pd.DataFrame):
    """log1p on the skewed demand features (LOG_FEATURES), then scales every
    feature to [0, 1]. Returns the scaled array and the fitted scaler (needed
    to map cluster centroids back to real units; see to_original_units)."""
    values = frame.values.astype(float).copy()
    cols = _log_columns()
    values[:, cols] = np.log1p(values[:, cols])
    scaler = MinMaxScaler()
    scaled = scaler.fit_transform(values)
    return scaled, scaler


def to_original_units(scaler: MinMaxScaler, scaled: np.ndarray) -> np.ndarray:
    """Inverse of normalize_features: undoes the min-max scaling, then the
    log1p, so centroids come back in real units (units/day, pesos)."""
    values = scaler.inverse_transform(scaled)
    cols = _log_columns()
    values[:, cols] = np.expm1(values[:, cols])
    return values
