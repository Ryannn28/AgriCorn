import sys
import json
import os
import base64
from datetime import datetime, timedelta

def predict_growth(data):
    try:
        # Extract inputs with defaults and null handling
        planting_date_str = data.get('planting_date') or datetime.now().strftime('%Y-%m-%d')
        variety = str(data.get('corn_variety') or 'Unknown').lower()
        soil_type = str(data.get('soil_type') or 'Loam').lower()
        area_value = float(data.get('area_value') or 1.0)
        area_unit = str(data.get('area_unit') or 'hectare').lower()
        density = float(data.get('density') or 60000) # plants per hectare
        seeds_per_hole = int(data.get('seeds_per_hole') or 1)

        # Normalize area to hectares
        area_in_ha = area_value
        if area_unit == 'sqm':
            area_in_ha = area_value / 10000

        # Regression Factors
        
        # 1. Base Yield (Tons per Hectare)
        base_yield_ha = 5.5 # Average hybrid yield in PH
        
        # 2. Variety Factor
        # Precise variety factors for regression
        v_lower = str(variety).lower()
        variety_factor = 1.0
        days_to_maturity = 110 # Default
        
        if 'sweet' in v_lower:
            if 'hybrid' in v_lower: variety_factor = 0.8  # Hybrid sweet has good yield but lighter
            elif 'native' in v_lower: variety_factor = 0.65
            elif 'opv' in v_lower: variety_factor = 0.7
            else: variety_factor = 0.75
            days_to_maturity = 75
        elif 'yellow' in v_lower:
            if 'hybrid' in v_lower: variety_factor = 1.25 # High yield yellow hybrid
            elif 'feed' in v_lower: variety_factor = 1.15
            elif 'native' in v_lower: variety_factor = 0.95
            else: variety_factor = 1.1
            days_to_maturity = 115
        elif 'white' in v_lower:
            if 'field' in v_lower: variety_factor = 1.05
            elif 'native' in v_lower: variety_factor = 0.9
            else: variety_factor = 0.95
            days_to_maturity = 105
        elif 'glutinous' in v_lower or 'waxy' in v_lower:
            variety_factor = 0.85
            days_to_maturity = 90
        elif 'popcorn' in v_lower:
            variety_factor = 0.55 # Popcorn has low biomass/weight
            days_to_maturity = 100
        elif 'baby' in v_lower:
            variety_factor = 0.4 # Very low weight yield
            days_to_maturity = 60
        elif 'hybrid' in v_lower:
            variety_factor = 1.2
            days_to_maturity = 115
            
        variety_coefficient = variety_factor / 1.25 # Normalized against max factor05
            
        # 3. Soil Factor
        soil_factor = 1.0
        if 'loam' in soil_type:
            soil_factor = 1.15
        elif 'clay' in soil_type:
            soil_factor = 0.9
        elif 'sandy' in soil_type:
            soil_factor = 0.85
            
        # 4. Density Factor (Optimal density: 60k - 75k)
        density_factor = 1.0
        if density < 40000:
            density_factor = 0.8
        elif density > 80000:
            density_factor = 0.85 # Competition reduces yield per plant
        
        # 5. Seeds per Hole Factor
        seeds_factor = 1.0
        if seeds_per_hole > 2:
            seeds_factor = 0.9 # Crowding at the spot
            
        # Calculate Predicted Yield
        predicted_yield_ha = base_yield_ha * variety_factor * soil_factor * density_factor * seeds_factor
        total_predicted_yield = predicted_yield_ha * area_in_ha
        
        # Calculate Harvest Date
        planting_date = datetime.strptime(planting_date_str, '%Y-%m-%d')
        harvest_date = planting_date + timedelta(days=days_to_maturity)
        
        # Calculate Confidence Score (based on data completeness)
        completeness = 0
        if data.get('corn_variety'): completeness += 20
        if data.get('soil_type'): completeness += 20
        if data.get('density'): completeness += 20
        if data.get('area_value'): completeness += 20
        if data.get('planting_date'): completeness += 20
        
        confidence = completeness * 0.95 # Max 95% confidence for model
        
        # Prepare Result
        result = {
            "status": "success",
            "prediction": {
                "yield_tons_ha": round(predicted_yield_ha, 2),
                "total_yield_tons": round(total_predicted_yield, 2),
                "total_yield_sacks": round(total_predicted_yield * 20, 1), # Roughly 50kg sacks
                "harvest_date": harvest_date.strftime('%Y-%m-%d'),
                "days_to_maturity": days_to_maturity,
                "confidence": f"{confidence:.1f}%",
                "factors": {
                    "soil_coefficient": soil_factor,
                    "variety_coefficient": variety_factor,
                    "density_efficiency": density_factor
                }
            }
        }
        
        return result

    except Exception as e:
        return {"status": "error", "message": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"status": "error", "message": "No input data provided"}))
    else:
        try:
            # Try to decode as base64 first (more reliable for Windows CLI)
            try:
                raw_input = base64.b64decode(sys.argv[1]).decode('utf-8')
                input_data = json.loads(raw_input)
            except:
                # Fallback to direct JSON
                input_data = json.loads(sys.argv[1])
                
            print(json.dumps(predict_growth(input_data)))
        except Exception as e:
            print(json.dumps({"status": "error", "message": f"Input parse error: {str(e)}"}))
