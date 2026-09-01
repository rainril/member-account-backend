<?php
// ============================================================
// seed_programs.php
// ONE-TIME SEEDER: run this once by visiting it in your browser
// (http://localhost/memberaccount/seed_programs.php), then delete
// it or leave it -- running it again will just add duplicates,
// so only run it once.
//
// Inserts the 6 workout programs (with their Day A/B tabs and
// exercises) into Programs, ProgramDays, and ProgramExercises.
// ============================================================

require "db_connect.php";

header("Content-Type: text/plain");

$programs = [
    [
        'name' => 'Chest Builder', 'muscle' => 'Chest',
        'desc' => 'A balanced chest program targeting the upper, middle, and lower pecs for full development.',
        'duration' => '45–55 min', 'freq' => 2, 'level' => 'Intermediate',
        'theme' => '#00B4D8', 'cardBg' => '#F4FBFD', 'border' => '#CFEEF7', 'badgeBg' => '#FFF3CD', 'badgeText' => '#FFB703',
        'days' => [
            'Day A - Mass Focus' => [
                ['Flat Barbell Bench Press', 'Drive feet into the floor, retract scapula', '4', '6–8', '2 min'],
                ['Incline Dumbbell Press', '', '3', '10–12', '90s'],
                ['Cable Chest Fly', '', '3', '12–15', '60s'],
                ['Push-Up Burnout', '', '2', 'To failure', '60s'],
            ],
            'Day B - Detail Focus' => [
                ['Incline Barbell Press', '', '4', '8–10', '90s'],
                ['Decline Dumbbell Press', '', '3', '10–12', '90s'],
                ['Pec Deck Machine', '', '3', '15–20', '60s'],
                ['Dips (chest variation)', 'Lean forward to emphasize chest', '3', '10–12', '90s'],
            ],
        ],
    ],
    [
        'name' => 'Back & Thickness', 'muscle' => 'Back',
        'desc' => 'Develops a wide, thick back with compound pulls and targeted isolation work.',
        'duration' => '55–65 min', 'freq' => 2, 'level' => 'Intermediate',
        'theme' => '#D4A373', 'cardBg' => '#FFFDF6', 'border' => '#FEF0CD', 'badgeBg' => '#FFF3CD', 'badgeText' => '#FFB703',
        'days' => [
            'Day A - Width' => [
                ['Pull-Up (weighted)', 'Full dead hang, drive elbows to hips', '4', '6–8', '2 min'],
                ['Lat Pulldown', '', '3', '10–12', '90s'],
                ['Straight-Arm Pulldown', '', '3', '12–15', '60s'],
                ['Single-Arm Dumbbell Row', '', '3', '10–12 each', '60s'],
            ],
            'Day B - Thickness' => [
                ['Deadlift', 'Neutral spine, push the floor away', '4', '5', '3 min'],
                ['Barbell Bent-Over Row', '', '4', '8–10', '90s'],
                ['Seated Cable Row', '', '3', '12–15', '60s'],
                ['Face Pull', '', '3', '15–20', '60s'],
            ],
        ],
    ],
    [
        'name' => 'Leg Power', 'muscle' => 'Legs',
        'desc' => 'A comprehensive lower-body program covering quads, hamstrings, glutes, and calves.',
        'duration' => '60–75 min', 'freq' => 2, 'level' => 'Advanced',
        'theme' => '#9C27B0', 'cardBg' => '#FDFBFE', 'border' => '#F3E5F5', 'badgeBg' => '#F8D7DA', 'badgeText' => '#DC3545',
        'days' => [
            'Day A - Quad Dominant' => [
                ['Back Squat', 'Break parallel, brace your core', '5', '5', '3 min'],
                ['Leg Press', '', '3', '12–15', '90s'],
                ['Walking Lunges', '', '3', '12 each', '90s'],
                ['Leg Extension', '', '3', '15–20', '60s'],
                ['Calf Raises (Standing)', '', '4', '15–20', '45s'],
            ],
            'Day B - Posterior Chain' => [
                ['Romanian Deadlift', 'Hinge at hips, feel hamstring stretch', '4', '8–10', '2 min'],
                ['Bulgarian Split Squat', '', '3', '10–12 each', '90s'],
                ['Leg Curl (Seated)', '', '3', '12–15', '60s'],
                ['Hip Thrust', '', '4', '10–12', '90s'],
                ['Seated Calf Raises', '', '4', '15–20', '45s'],
            ],
        ],
    ],
    [
        'name' => 'Shoulder Sculptor', 'muscle' => 'Shoulders',
        'desc' => 'Build broad, round 3D shoulders with targeted anterior, lateral, and rear delt work.',
        'duration' => '40–50 min', 'freq' => 2, 'level' => 'Beginner',
        'theme' => '#E65100', 'cardBg' => '#FFFBF9', 'border' => '#FFE0CC', 'badgeBg' => '#D1E7DD', 'badgeText' => '#0F5132',
        'days' => [
            'Day A - Pressing' => [
                ['Seated Dumbbell Press', '', '4', '10–12', '90s'],
                ['Lateral Raises', 'Lead with elbows, slight lean', '4', '12–15', '60s'],
                ['Front Raises', '', '3', '12–15', '60s'],
                ['Rear Delt Machine Fly', '', '3', '15–20', '60s'],
            ],
            'Day B - Isolation' => [
                ['Arnold Press', '', '3', '10–12', '90s'],
                ['Cable Lateral Raise', '', '3', '15–20 each', '45s'],
                ['Reverse Pec Deck', '', '4', '15–20', '60s'],
                ['Face Pull', '', '3', '15–20', '60s'],
            ],
        ],
    ],
    [
        'name' => 'Arm Blast', 'muscle' => 'Biceps & Triceps',
        'desc' => 'Supersets and isolation work to maximize arm pump and stimulate growth.',
        'duration' => '45–55 min', 'freq' => 2, 'level' => 'Beginner',
        'theme' => '#E91E63', 'cardBg' => '#FFF1F6', 'border' => '#FBCFE8', 'badgeBg' => '#D1E7DD', 'badgeText' => '#0F5132',
        'days' => [
            'Day A - Biceps' => [
                ['Barbell Curl', 'No swinging, full range', '4', '8–10', '90s'],
                ['Incline Dumbbell Curl', '', '3', '10–12', '60s'],
                ['Hammer Curl', '', '3', '10–12', '60s'],
                ['Cable Curl', '', '3', '15–20', '45s'],
            ],
            'Day B - Triceps' => [
                ['Close-Grip Bench Press', '', '4', '8–10', '90s'],
                ['Skull Crushers', '', '3', '10–12', '90s'],
                ['Tricep Pushdown (Rope)', '', '3', '12–15', '60s'],
                ['Overhead Tricep Extension', '', '3', '12–15', '60s'],
            ],
        ],
    ],
    [
        'name' => 'Core & Abs', 'muscle' => 'Core',
        'desc' => 'A focused core routine covering all planes of motion for a strong, visible midsection.',
        'duration' => '25–35 min', 'freq' => 3, 'level' => 'Beginner',
        'theme' => '#2E7D32', 'cardBg' => '#F0FDF4', 'border' => '#DCFCE7', 'badgeBg' => '#D1E7DD', 'badgeText' => '#0F5132',
        'days' => [
            'Routine A - Strength' => [
                ['Hanging Leg Raise', '', '4', '12–15', '60s'],
                ['Cable Crunch', '', '4', '15–20', '60s'],
                ['Ab Wheel Rollout', 'Keep hips from sagging', '3', '10–12', '60s'],
                ['Russian Twist (weighted)', '', '3', '20', '45s'],
            ],
            'Routine B - Endurance' => [
                ['Plank', '', '3', '60s', '45s'],
                ['Side Plank', '', '3', '45s each', '45s'],
                ['Mountain Climbers', '', '3', '30 each', '60s'],
                ['Bicycle Crunch', '', '3', '20 each', '45s'],
            ],
        ],
    ],
];

$programStmt = $conn->prepare(
    "INSERT INTO Programs (ProgramName, MuscleGroup, Description, DurationRange, FrequencyPerWeek, Level,
                            ThemeColorHex, CardBgHex, BorderColorHex, BadgeBgHex, BadgeTextColorHex)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$dayStmt = $conn->prepare("INSERT INTO ProgramDays (ProgramID, DayLabel, DisplayOrder) VALUES (?, ?, ?)");
$exStmt = $conn->prepare(
    "INSERT INTO ProgramExercises (ProgramDayID, ExerciseName, Tip, Sets, Reps, Rest, DisplayOrder)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

$totalPrograms = 0;
$totalDays = 0;
$totalExercises = 0;

foreach ($programs as $p) {
    $programStmt->bind_param(
        "sssssssssss",
        $p['name'], $p['muscle'], $p['desc'], $p['duration'], $p['freq'], $p['level'],
        $p['theme'], $p['cardBg'], $p['border'], $p['badgeBg'], $p['badgeText']
    );
    $programStmt->execute();
    $programId = $programStmt->insert_id;
    $totalPrograms++;

    $dayOrder = 0;
    foreach ($p['days'] as $dayLabel => $exercises) {
        $dayStmt->bind_param("isi", $programId, $dayLabel, $dayOrder);
        $dayStmt->execute();
        $dayId = $dayStmt->insert_id;
        $totalDays++;
        $dayOrder++;

        $exOrder = 0;
        foreach ($exercises as $ex) {
            [$name, $tip, $sets, $reps, $rest] = $ex;
            $exStmt->bind_param("isssssi", $dayId, $name, $tip, $sets, $reps, $rest, $exOrder);
            $exStmt->execute();
            $totalExercises++;
            $exOrder++;
        }
    }
}

$programStmt->close();
$dayStmt->close();
$exStmt->close();
$conn->close();

echo "Done!\n";
echo "Inserted $totalPrograms programs, $totalDays days, $totalExercises exercises.\n";
echo "You can now delete this file (seed_programs.php) if you'd like.\n";
?>
