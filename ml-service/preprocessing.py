"""Feature preprocessing for the K-Means clustering microservice."""

import pandas as pd
from sklearn.preprocessing import MinMaxScaler

from config import FEATURES


def to_feature_frame(items: list[dict]) -> pd.DataFrame:
    """Builds a DataFrame of the model's feature columns, in order, from
    the raw item dicts posted by the CI4 backend."""
    return pd.DataFrame(items)[FEATURES].astype(float)


def normalize_features(frame: pd.DataFrame):
    """Scales every feature to [0, 1]. Returns the scaled array and the
    fitted scaler (needed to map cluster centroids back to real units)."""
    scaler = MinMaxScaler()
    scaled = scaler.fit_transform(frame.values)
    return scaled, scaler
