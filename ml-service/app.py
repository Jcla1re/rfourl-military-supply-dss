from flask import Flask, jsonify, request

from config import (
    ABC_CLASS_BY_RANK,
    CLUSTER_NAMES_BY_RANK,
    FEATURES,
    K,
    SERVICE_LEVEL_BY_CLASS,
    Z_SCORE_MAP,
)
from kmeans_model import KMeansClusterModel
from preprocessing import normalize_features, to_feature_frame

app = Flask(__name__)


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})


@app.route("/cluster", methods=["POST"])
def cluster_inventory():
    """
    Expects JSON payload from the CI4 DSS engine:
    {
        "items": [
            {"item_id": "ITM-0001", "unit_cost": 541.75, "avg_daily_demand": 0.08,
             "demand_std_dev": 0.48, "lead_time_days": 5},
            ...
        ],
        "n_clusters": 3
    }
    Returns each item's cluster assignment, ranked and named by demand
    level (highest-demand cluster = "Fast-Moving", etc.), plus the
    cluster centroids in original (unscaled) units.

    When n_clusters == 3 (the standard scheme), each item also gets an
    ABC policy tier (abc_class), its target service_level, and the
    corresponding z_score — i.e. the K-Means output directly drives the
    service level used downstream in Safety Stock / Reorder Point, not
    just a descriptive label.
    """
    payload = request.get_json(silent=True) or {}
    items = payload.get("items", [])
    n_clusters = int(payload.get("n_clusters", K))

    if not items:
        return jsonify({"error": "no items provided"}), 400
    if len(items) < n_clusters:
        return jsonify({"error": f"need at least {n_clusters} items to form {n_clusters} clusters"}), 400

    missing_fields = [f for f in FEATURES if any(f not in item for item in items)]
    if missing_fields:
        return jsonify({"error": f"items missing required feature(s): {missing_fields}"}), 400

    frame = to_feature_frame(items)
    X_scaled, scaler = normalize_features(frame)

    model = KMeansClusterModel(n_clusters=n_clusters)
    labels, centers_scaled = model.fit_predict(X_scaled)

    demand_idx = FEATURES.index("avg_daily_demand")
    order = sorted(range(n_clusters), key=lambda c: centers_scaled[c][demand_idx], reverse=True)
    rank_of_cluster = {cluster_id: rank for rank, cluster_id in enumerate(order)}

    names        = CLUSTER_NAMES_BY_RANK if n_clusters == len(CLUSTER_NAMES_BY_RANK) else None
    abc_classes  = ABC_CLASS_BY_RANK if n_clusters == len(ABC_CLASS_BY_RANK) else None

    clusters = []
    for idx, label in enumerate(labels):
        rank = rank_of_cluster[int(label)]
        abc_class     = abc_classes[rank] if abc_classes else None
        service_level = SERVICE_LEVEL_BY_CLASS.get(abc_class) if abc_class else None
        z_score       = Z_SCORE_MAP.get(service_level) if service_level is not None else None

        clusters.append({
            "item_id": items[idx].get("item_id"),
            "cluster_label": int(label),
            "cluster_rank": rank,
            "cluster_name": names[rank] if names else f"Cluster {rank}",
            "abc_class": abc_class,
            "service_level": service_level,
            "z_score": z_score,
        })

    centers_original = scaler.inverse_transform(centers_scaled)
    centers = [
        {FEATURES[j]: float(centers_original[i][j]) for j in range(len(FEATURES))}
        for i in range(n_clusters)
    ]

    return jsonify({"clusters": clusters, "centers": centers, "n_clusters": n_clusters})


if __name__ == "__main__":
    app.run(port=5001, debug=True)
