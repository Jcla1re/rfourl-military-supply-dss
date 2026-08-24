from flask import Flask, request, jsonify
from sklearn.cluster import KMeans
import numpy as np

app = Flask(__name__)

@app.route("/cluster", methods=["POST"])
def cluster_inventory():
    """
    Expects JSON payload from CI4 controller:
    {
        "items": [
            {"sku": "UNI-M", "avg_demand": 20, "lead_time": 7, "demand_variability": 4.2},
            ...
        ],
        "n_clusters": 3
    }
    Returns cluster assignment per SKU.
    """
    payload = request.get_json()
    items = payload.get("items", [])
    n_clusters = payload.get("n_clusters", 3)

    if not items:
        return jsonify({"error": "no items provided"}), 400

    X = np.array([[i["avg_demand"], i["lead_time"], i["demand_variability"]] for i in items])
    model = KMeans(n_clusters=n_clusters, random_state=42, n_init=10)
    labels = model.fit_predict(X)

    result = [
        {"sku": items[idx]["sku"], "cluster": int(label)}
        for idx, label in enumerate(labels)
    ]
    return jsonify({"clusters": result})


if __name__ == "__main__":
    app.run(port=5001, debug=True)
