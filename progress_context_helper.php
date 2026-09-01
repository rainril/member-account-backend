<?php
// ============================================================
// progress_context_helper.php
// Builds a compact "progress snapshot" for a member: latest BMI +
// recent trend, last 7 days of food totals, and their most recent
// body photo. Intended to eventually be handed to the AI chatbot
// (chatbot_image_api.php / chatbot_api.php) as extra context so it
// can give informed insights -- NOT wired into that flow yet, this
// file just builds the data.
//
// Usage:
//   require "db_connect.php";
//   require "progress_context_helper.php";
//   $context = getMemberProgressContext($conn, $memberId);
// ============================================================

/**
 * Gathers a member's recent BMI trend, last 7 days of food totals,
 * and most recent body photo into one array.
 *
 * @param mysqli $conn     An open mysqli connection (caller owns it --
 *                          this function does not open or close it).
 * @param int    $memberId The member to build the snapshot for.
 * @return array{
 *   latest_bmi: float|null,
 *   latest_bmi_date: string|null,
 *   latest_weight_kg: float|null,
 *   latest_height_cm: float|null,
 *   bmi_trend: array,
 *   bmi_trend_direction: string|null,
 *   food_totals_7_days: array,
 *   latest_photo_path: string|null,
 *   latest_photo_date: string|null
 * }
 */
function getMemberProgressContext(mysqli $conn, int $memberId): array {
    $context = [
        "latest_bmi"          => null,
        "latest_bmi_date"     => null,
        "latest_weight_kg"    => null,
        "latest_height_cm"    => null,
        "bmi_trend"           => [],
        "bmi_trend_direction" => null,
        "food_totals_7_days"  => [
            "calories" => 0,
            "protein"  => 0.0,
            "carbs"    => 0.0,
            "fats"     => 0.0,
            "days_logged" => 0,
        ],
        "latest_photo_path"   => null,
        "latest_photo_date"   => null,
    ];

    if ($memberId <= 0) {
        return $context;
    }

    // ------------------------------------------------------------
    // Latest BMI + recent trend (last 5 entries, newest first)
    // ------------------------------------------------------------
    $stmt = $conn->prepare(
        "SELECT BMI, Weight, Height, RecordedAt FROM BodyMetrics
         WHERE MemberID = ?
         ORDER BY RecordedAt DESC, MetricID DESC
         LIMIT 5"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();

    $trend = [];
    while ($row = $result->fetch_assoc()) {
        $trend[] = [
            "bmi"          => floatval($row["BMI"]),
            "weight_kg"    => floatval($row["Weight"]),
            "height_cm"    => floatval($row["Height"]),
            "recorded_at"  => $row["RecordedAt"],
        ];
    }
    $stmt->close();

    if (!empty($trend)) {
        $context["latest_bmi"]       = $trend[0]["bmi"];
        $context["latest_bmi_date"]  = $trend[0]["recorded_at"];
        $context["latest_weight_kg"] = $trend[0]["weight_kg"];
        $context["latest_height_cm"] = $trend[0]["height_cm"];
        $context["bmi_trend"]        = $trend;

        $oldest = $trend[count($trend) - 1]["bmi"];
        $newest = $trend[0]["bmi"];
        if ($newest > $oldest) {
            $context["bmi_trend_direction"] = "up";
        } elseif ($newest < $oldest) {
            $context["bmi_trend_direction"] = "down";
        } else {
            $context["bmi_trend_direction"] = "stable";
        }
    }

    // ------------------------------------------------------------
    // Last 7 days of food totals (today back through 6 days ago)
    // ------------------------------------------------------------
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(Calories), 0) AS total_calories,
                COALESCE(SUM(Protein), 0) AS total_protein,
                COALESCE(SUM(Carbs), 0) AS total_carbs,
                COALESCE(SUM(Fats), 0) AS total_fats,
                COUNT(DISTINCT LoggedAt) AS days_logged
         FROM FoodLogs
         WHERE MemberID = ? AND LoggedAt >= (CURDATE() - INTERVAL 6 DAY)"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $context["food_totals_7_days"] = [
            "calories"    => intval($row["total_calories"]),
            "protein"     => floatval($row["total_protein"]),
            "carbs"       => floatval($row["total_carbs"]),
            "fats"        => floatval($row["total_fats"]),
            "days_logged" => intval($row["days_logged"]),
        ];
    }

    // ------------------------------------------------------------
    // Most recent body photo
    // ------------------------------------------------------------
    $stmt = $conn->prepare(
        "SELECT PhotoURL, UploadDate FROM BodyPhotos
         WHERE MemberID = ?
         ORDER BY UploadDate DESC, PhotoID DESC
         LIMIT 1"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $photo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($photo) {
        $context["latest_photo_path"] = $photo["PhotoURL"];
        $context["latest_photo_date"] = $photo["UploadDate"];
    }

    return $context;
}
?>
