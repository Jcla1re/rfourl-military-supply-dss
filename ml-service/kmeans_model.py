"""K-Means model wrapper for the clustering microservice."""

import numpy as np
from sklearn.cluster import KMeans

from config import N_INIT, RANDOM_STATE


class KMeansClusterModel:
    def __init__(self, n_clusters: int, random_state: int = RANDOM_STATE, n_init: int = N_INIT):
        self.n_clusters = n_clusters
        self.model = KMeans(n_clusters=n_clusters, random_state=random_state, n_init=n_init)

    def fit_predict(self, X_scaled: np.ndarray):
        """Fits the model and returns (labels, cluster_centers_) — both
        in the scaled [0,1] feature space."""
        labels = self.model.fit_predict(X_scaled)
        return labels, self.model.cluster_centers_
