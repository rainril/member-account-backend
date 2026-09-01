<?php
// ============================================================
// food_search_api.php
// GET ?query=X -> search USDA FoodData Central and return a
// simplified, flat list of matches (food name + calories/protein/
// carbs/fats per serving) for the Flutter Add Food form to
// autocomplete from. Read-only -- does not touch FoodLogs.
// ============================================================

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/sync_debug.log');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once "usda_config.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    echo json_encode(["success" => false, "message" => "Unsupported request method."]);
    exit();
}

$query = trim($_GET["query"] ?? "");

if (mb_strlen($query) < 2) {
    echo json_encode(["success" => false, "message" => "Search query must be at least 2 characters."]);
    exit();
}

$maxResults = 10;

/**
 * Finds the value of the first nutrient in a FoodData Central
 * foodNutrients array whose name contains $namePattern (case-insensitive).
 * Optionally also requires a matching unit (e.g. so "Energy" in KCAL
 * isn't confused with the parallel "Energy" entry reported in kJ).
 */
function extractNutrientValue(array $nutrients, string $namePattern, ?string $unitFilter = null): float {
    foreach ($nutrients as $nutrient) {
        $name = $nutrient["nutrientName"] ?? "";
        if (stripos($name, $namePattern) === false) {
            continue;
        }
        if ($unitFilter !== null && strtoupper($nutrient["unitName"] ?? "") !== strtoupper($unitFilter)) {
            continue;
        }
        return floatval($nutrient["value"] ?? 0);
    }
    return 0.0;
}

$requestUrl = "https://api.nal.usda.gov/fdc/v1/foods/search?" . http_build_query([
    "api_key"  => USDA_API_KEY,
    "query"    => $query,
    "pageSize" => $maxResults,
]);

$ch = curl_init($requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log("food_search_api: USDA request failed: " . $curlError);
    echo json_encode(["success" => false, "message" => "Could not reach the food database. Please try again."]);
    exit();
}

if ($httpCode !== 200) {
    error_log("food_search_api: USDA API returned HTTP $httpCode: " . $response);
    echo json_encode(["success" => false, "message" => "Could not reach the food database. Please try again."]);
    exit();
}

$data = json_decode($response, true);
if (!is_array($data)) {
    error_log("food_search_api: USDA API returned unparseable response: " . $response);
    echo json_encode(["success" => false, "message" => "Could not reach the food database. Please try again."]);
    exit();
}

$foods = $data["foods"] ?? [];

$results = [];
foreach (array_slice($foods, 0, $maxResults) as $food) {
    $nutrients = $food["foodNutrients"] ?? [];

    $results[] = [
        "fdc_id"    => $food["fdcId"] ?? null,
        "food_name" => $food["description"] ?? "Unknown food",
        "calories"  => extractNutrientValue($nutrients, "Energy", "KCAL"),
        "protein"   => extractNutrientValue($nutrients, "Protein"),
        "carbs"     => extractNutrientValue($nutrients, "Carbohydrate"),
        "fats"      => extractNutrientValue($nutrients, "lipid"),
    ];
}

echo json_encode(["success" => true, "results" => $results]);
?>
