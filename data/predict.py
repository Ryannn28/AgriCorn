import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3' 

import sys
import json
import numpy as np
import tensorflow as tf
from tensorflow.keras.models import load_model
from tensorflow.keras.preprocessing import image


LEAF_DISEASE_CLASSES = {
    'blight',
    'common_rust',
    'gray_leaf_spot',
    'healthy'
}


def predict_corn(img_path):
    try:
        # Always use paths relative to this script's location
        base_dir = os.path.dirname(os.path.abspath(__file__))
        model_path = os.path.join(base_dir, 'corn_disease_pest_model_v3.keras')
        class_names_path = os.path.join(base_dir, 'class_names.json')

        if not os.path.exists(model_path):
            return {"status": "error", "message": f"Model file not found: {model_path}"}
        if not os.path.exists(class_names_path):
            return {"status": "error", "message": f"Class names file not found: {class_names_path}"}

        model = load_model(model_path)

        with open(class_names_path, 'r') as f:
            class_names = json.load(f)

        # Make sure the image path is absolute
        if not os.path.isabs(img_path):
            img_path = os.path.join(os.getcwd(), img_path)
        if not os.path.exists(img_path):
            return {"status": "error", "message": f"Image file not found: {img_path}"}

        img = image.load_img(img_path, target_size=(160, 160))
        img_rgb = image.img_to_array(img) / 255.0
        img_array = np.expand_dims(img_rgb, axis=0)

        predictions = model.predict(img_array, verbose=0)
        probs = predictions[0]
        score = float(np.max(probs))
        class_idx = int(np.argmax(probs))

        # Guardrails for non-corn/unclear images:
        # 1) confidence and class-separation thresholds
        # 2) simple corn context cue (green leaf ratio)
        sorted_scores = np.sort(probs)
        second_score = float(sorted_scores[-2]) if len(sorted_scores) > 1 else 0.0
        margin = score - second_score
        predicted_label = str(class_names[class_idx]).strip().lower()
        is_leaf_disease = predicted_label in LEAF_DISEASE_CLASSES

        # Estimate how much of the image contains green plant-like pixels.
        r = img_rgb[:, :, 0]
        g = img_rgb[:, :, 1]
        b = img_rgb[:, :, 2]
        green_mask = (g > 0.20) & (g > (r * 1.05)) & (g > (b * 1.05))
        green_ratio = float(np.mean(green_mask))

        if is_leaf_disease:
            min_conf = 0.68
            min_margin = 0.18
        else:
            min_conf = 0.88
            min_margin = 0.35

        is_supported = True
        if score < min_conf or margin < min_margin:
            is_supported = False
        if is_leaf_disease and green_ratio < 0.07:
            is_supported = False
        if (not is_leaf_disease) and green_ratio < 0.015 and score < 0.94:
            is_supported = False

        if not is_supported:
            return {
                "status": "unsupported",
                "message": "This scanner is for corn pests and diseases only. Please upload a clear image of a corn leaf, stalk, or corn-related pest/disease."
            }

        clean_class_name = class_names[class_idx].replace('_', ' ').title()

        return {
            "status": "success",
            "class": clean_class_name,
            "confidence": f"{score * 100:.2f}%"
        }

    except Exception as e:
        return {"status": "error", "message": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"status": "error", "message": "No image path"}))
    else:
        print(json.dumps(predict_corn(sys.argv[1])))