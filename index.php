<?php
class SAWDecisionSupport
{
    private $data = [];
    private $bobot = [];

    // Default weights if none provided
    private $defaultWeights = [
        'Engine_Size' => 2,   // Weight for engine size (lower is better)
        'Mileage' => 2,       // Weight for mileage (lower is better)
        'Doors' => 1,         // Weight for number of doors
        'Owner_Count' => 2,   // Weight for owner count (lower is better)
        'Price' => 3          // Weight for price (lower is better)
    ];


    // ssaat pertama kali dieksekusi
    public function __construct($csvFile, $userWeights = [])
    {
        // Read CSV file
        $this->data = $this->readCSV($csvFile);

        // Pakai default atau dari pengguna
        if (empty($userWeights)) {
            $this->bobot = $this->defaultWeights;
        } else {
            $totalWeight = array_sum($userWeights);
            if ($totalWeight > 0) {
                foreach ($userWeights as $criterion => $weight) {
                    $this->bobot[$criterion] = $weight / $totalWeight;
                }
            } else {
                $this->bobot = $this->defaultWeights;
            }
        }
    }


    // Fungsi untuk membaca data csv
    private function readCSV($file)
    {
        $data = [];
        if (($handle = fopen($file, 'r')) !== false) {
            // Skip header row
            $headers = fgetcsv($handle);

            while (($row = fgetcsv($handle)) !== false) {
                $data[] = [
                    'Brand' => $row[0],
                    'Model' => $row[1],
                    'Year' => $row[2],
                    'Engine_Size' => floatval($row[3]),
                    'Fuel_Type' => $row[4],
                    'Transmission' => $row[5],
                    'Mileage' => floatval($row[6]),
                    'Doors' => intval($row[7]),
                    'Owner_Count' => intval($row[8]),
                    'Price' => floatval($row[9])
                ];
            }
            fclose($handle);
        }
        return $data;
    }


    // Melakukan normalisasi data
    private function normalizeData()
    {
        $normalized = [];

        // min max dari tiap kriteria
        $maxMin = [
            'Engine_Size' => ['max' => PHP_FLOAT_MIN, 'min' => PHP_FLOAT_MAX],
            'Mileage' => ['max' => PHP_FLOAT_MIN, 'min' => PHP_FLOAT_MAX],
            'Doors' => ['max' => PHP_FLOAT_MIN, 'min' => PHP_FLOAT_MAX],
            'Owner_Count' => ['max' => PHP_FLOAT_MIN, 'min' => PHP_FLOAT_MAX],
            'Price' => ['max' => PHP_FLOAT_MIN, 'min' => PHP_FLOAT_MAX]
        ];

        // cari max and min values
        $criteria = array_keys($maxMin);
        foreach ($this->data as $car) {
            foreach ($criteria as $criterion) {
                $maxMin[$criterion]['max'] = max($maxMin[$criterion]['max'], $car[$criterion]);
                $maxMin[$criterion]['min'] = min($maxMin[$criterion]['min'], $car[$criterion]);
            }
        }

        // Normalize data
        foreach ($this->data as $index => $car) {
            $normalizedCar = $car;

            // Benefit criteria (higher is better): Doors
            $normalizedCar['Doors_Norm'] = $maxMin['Doors']['max'] > 0 ? ($car['Doors'] / $maxMin['Doors']['max']) : 0;

            // Cost criteria (lower is better): Engine_Size, Mileage, Owner_Count, Price
            $normalizedCar['Engine_Size_Norm'] = $car['Engine_Size'] > 0 ? ($maxMin['Engine_Size']['min'] / $car['Engine_Size']) : 0;
            $normalizedCar['Mileage_Norm'] = $car['Mileage'] > 0 ? ($maxMin['Mileage']['min'] / $car['Mileage']) : 0;
            $normalizedCar['Owner_Count_Norm'] = $car['Owner_Count'] > 0 ? ($maxMin['Owner_Count']['min'] / $car['Owner_Count']) : 0;
            $normalizedCar['Price_Norm'] = $car['Price'] > 0 ? ($maxMin['Price']['min'] / $car['Price']) : 0;

            $normalized[$index] = $normalizedCar;
        }

        return $normalized;
    }

    public function calculatePreference()
    {
        $normalized = $this->normalizeData() ?? 0;
        $preferences = [];

        foreach ($normalized as $index => $car) {
            $preference =
                ($car['Engine_Size_Norm'] * $this->bobot['Engine_Size']) +
                ($car['Mileage_Norm'] * $this->bobot['Mileage']) +
                ($car['Doors_Norm'] * $this->bobot['Doors']) +
                ($car['Owner_Count_Norm'] * $this->bobot['Owner_Count']) +
                ($car['Price_Norm'] * $this->bobot['Price']);

            $preferences[$index] = [
                'car' => $this->data[$index],
                'preference_score' => $preference
            ];
        }

        // Sort by preference score in descending order
        usort($preferences, function ($a, $b) {
            return $b['preference_score'] <=> $a['preference_score'];
        });

        return $preferences;
    }
}

// Handle form submission
$results = null;
$error = null;
$currentWeights = [
    'Engine_Size' => 2,
    'Mileage' => 2,
    'Doors' => 1,
    'Owner_Count' => 2,
    'Price' => 3
];

// Persist uploaded file and weights across pagination using session
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle CSV file upload
    if (isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] == 0) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // To avoid filename collision, use a unique name
        $ext = pathinfo($_FILES['csvfile']['name'], PATHINFO_EXTENSION);
        $unique_name = uniqid('car_', true) . '.' . $ext;
        $upload_file = $upload_dir . $unique_name;

        if (move_uploaded_file($_FILES['csvfile']['tmp_name'], $upload_file)) {
            try {
                // Get user-defined weights if provided
                $userWeights = [];
                if (isset($_POST['weights'])) {
                    foreach ($_POST['weights'] as $criterion => $weight) {
                        $userWeights[$criterion] = floatval($weight);
                    }
                    $currentWeights = $userWeights;
                }

                // Store file and weights in session for pagination
                $_SESSION['upload_file'] = $upload_file;
                $_SESSION['user_weights'] = $userWeights;

                $decisionSupport = new SAWDecisionSupport($upload_file, $userWeights);
                $results = $decisionSupport->calculatePreference();
            } catch (Exception $e) {
                $error = "Error processing file: " . $e->getMessage();
            }
        } else {
            $error = "File upload failed.";
        }
    }
    // Handle weights-only update (if file was already uploaded)
    elseif (isset($_POST['weights']) && isset($_POST['previous_file']) && !empty($_POST['previous_file'])) {
        $upload_file = $_POST['previous_file'];

        if (file_exists($upload_file)) {
            try {
                $userWeights = [];
                foreach ($_POST['weights'] as $criterion => $weight) {
                    $userWeights[$criterion] = floatval($weight);
                }
                $currentWeights = $userWeights;

                // Update session
                $_SESSION['upload_file'] = $upload_file;
                $_SESSION['user_weights'] = $userWeights;

                $decisionSupport = new SAWDecisionSupport($upload_file, $userWeights);
                $results = $decisionSupport->calculatePreference();
            } catch (Exception $e) {
                $error = "Error processing file: " . $e->getMessage();
            }
        } else {
            $error = "Previous file not found. Please upload a new file.";
        }
    }
} elseif (isset($_SESSION['upload_file']) && file_exists($_SESSION['upload_file'])) {
    // Handle pagination GET request (no file upload, just page change)
    $upload_file = $_SESSION['upload_file'];
    $userWeights = isset($_SESSION['user_weights']) ? $_SESSION['user_weights'] : [];
    $currentWeights = !empty($userWeights) ? $userWeights : $currentWeights;

    try {
        $decisionSupport = new SAWDecisionSupport($upload_file, $userWeights);
        $results = $decisionSupport->calculatePreference();
    } catch (Exception $e) {
        $error = "Error processing file: " . $e->getMessage();
    }
}
?>
<!-- HTML Section -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Selection Decision Support System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --dark-color: #2c3e50;
            --light-color: #ecf0f1;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 20px;
            padding-bottom: 50px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            margin-bottom: 0;
            font-weight: 600;
        }

        .page-header p {
            margin-top: 10px;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background-color: var(--dark-color);
            color: white;
            font-weight: 600;
            border-top-left-radius: 8px !important;
            border-top-right-radius: 8px !important;
        }

        .upload-section {
            border-left: 4px solid var(--primary-color);
        }

        .criteria-section {
            border-left: 4px solid var(--secondary-color);
        }

        .form-control,
        .form-select {
            border-radius: 6px;
            border: 1px solid #ced4da;
            padding: 10px 15px;
            font-size: 16px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .weight-slider .form-range::-webkit-slider-thumb {
            background: var(--secondary-color);
        }

        .weight-slider .form-range::-moz-range-thumb {
            background: var(--secondary-color);
        }

        .weight-slider .form-range::-ms-thumb {
            background: var(--secondary-color);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 10px 20px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }

        .criteria-weights {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
        }

        .weight-item {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
            border-left: 3px solid var(--secondary-color);
        }

        .total-weight {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 500;
            margin-top: 20px;
            border-left: 3px solid var(--primary-color);
        }

        .weight-value {
            font-weight: bold;
            font-size: 1.2rem;
        }

        .badge-cost {
            background-color: var(--danger-color);
            color: white;
            font-size: 0.8rem;
            padding: 5px 8px;
            border-radius: 4px;
        }

        .badge-benefit {
            background-color: var(--secondary-color);
            color: white;
            font-size: 0.8rem;
            padding: 5px 8px;
            border-radius: 4px;
        }

        .table {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .table thead th {
            background-color: var(--dark-color);
            color: white;
            font-weight: 500;
            border: none;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .rank-1 {
            background-color: rgba(46, 204, 113, 0.1) !important;
        }

        .rank-2 {
            background-color: rgba(46, 204, 113, 0.05) !important;
        }

        .rank-3 {
            background-color: rgba(46, 204, 113, 0.02) !important;
        }

        .preference-score {
            font-weight: bold;
            color: var(--primary-color);
        }

        .results-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 3px solid var(--primary-color);
        }

        .results-info ul {
            margin-bottom: 0;
        }

        .weight-indicator {
            display: inline-block;
            width: 15px;
            height: 15px;
            margin-right: 5px;
            border-radius: 50%;
        }

        .error {
            color: var(--danger-color);
            padding: 10px;
            border-radius: 5px;
            background-color: rgba(231, 76, 60, 0.1);
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }

            .card {
                margin-bottom: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header text-center">
            <h1><i class="fas fa-car"></i> Car Selection Decision Support System</h1>
            <p>Using Simple Additive Weighting (SAW) Method</p>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="card upload-section">
                    <div class="card-header">
                        <i class="fas fa-file-upload me-2"></i> Upload Data & Set Criteria
                    </div>
                    <div class="card-body">

                        <!-- Memasukkan data => tampilan awal -->
                        <form action="" method="POST" enctype="multipart/form-data" id="sawForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <label for="csvfile" class="form-label">CSV File with Car Data</label>
                                        <div class="input-group">
                                            <?php if (isset($results) && isset($upload_file)): ?>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(basename($upload_file)); ?>" readonly>
                                                <span class="input-group-text"><i class="fas fa-file-csv"></i></span>
                                            <?php else: ?>
                                                <input type="file" class="form-control" name="csvfile" id="csvfile" accept=".csv" required>
                                                <span class="input-group-text"><i class="fas fa-file-csv"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-text">
                                            <?php if (isset($results) && isset($upload_file)): ?>
                                                File uploaded: <strong><?php echo htmlspecialchars(basename($upload_file)); ?></strong>
                                            <?php else: ?>
                                                Upload a CSV file with car data matching the required format.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="alert alert-info" role="alert">
                                        <i class="fas fa-info-circle me-2"></i> Set the importance of each criterion below. Total weight should equal 10.0
                                    </div>
                                </div>
                            </div>

                            <?php if (isset($results) && isset($upload_file)): ?>
                                <input type="hidden" name="previous_file" value="<?php echo htmlspecialchars($upload_file); ?>">
                            <?php endif; ?>

                            <div class="card criteria-section mb-4">
                                <div class="card-header">
                                    <i class="fas fa-sliders-h me-2"></i> Criteria Weights
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <div class="weight-item">
                                                <label for="engine_size_weight" class="form-label">
                                                    Engine Size <span class="badge badge-cost">Cost</span>
                                                </label>
                                                <div class="weight-slider">
                                                    <input type="range" class="form-range weight-range" min="0" max="10" step="0.1"
                                                        id="engine_size_weight" name="weights[Engine_Size]"
                                                        value="<?php echo $currentWeights['Engine_Size']; ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <span>0</span>
                                                        <span class="weight-value" id="engine_size_value"><?php echo $currentWeights['Engine_Size']; ?></span>
                                                        <span>10</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <div class="weight-item">
                                                <label for="mileage_weight" class="form-label">
                                                    Mileage <span class="badge badge-cost">Cost</span>
                                                </label>
                                                <div class="weight-slider">
                                                    <input type="range" class="form-range weight-range" min="0" max="10" step="0.1"
                                                        id="mileage_weight" name="weights[Mileage]"
                                                        value="<?php echo $currentWeights['Mileage']; ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <span>0</span>
                                                        <span class="weight-value" id="mileage_value"><?php echo $currentWeights['Mileage']; ?></span>
                                                        <span>10</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <div class="weight-item">
                                                <label for="doors_weight" class="form-label">
                                                    Doors <span class="badge badge-benefit">Benefit</span>
                                                </label>
                                                <div class="weight-slider">
                                                    <input type="range" class="form-range weight-range" min="0" max="10" step="0.1"
                                                        id="doors_weight" name="weights[Doors]"
                                                        value="<?php echo $currentWeights['Doors']; ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <span>0</span>
                                                        <span class="weight-value" id="doors_value"><?php echo $currentWeights['Doors']; ?></span>
                                                        <span>10</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <div class="weight-item">
                                                <label for="owner_count_weight" class="form-label">
                                                    Owner Count <span class="badge badge-cost">Cost</span>
                                                </label>
                                                <div class="weight-slider">
                                                    <input type="range" class="form-range weight-range" min="0" max="10" step="0.1"
                                                        id="owner_count_weight" name="weights[Owner_Count]"
                                                        value="<?php echo $currentWeights['Owner_Count']; ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <span>0</span>
                                                        <span class="weight-value" id="owner_count_value"><?php echo $currentWeights['Owner_Count']; ?></span>
                                                        <span>10</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <div class="weight-item">
                                                <label for="price_weight" class="form-label">
                                                    Price <span class="badge badge-cost">Cost</span>
                                                </label>
                                                <div class="weight-slider">
                                                    <input type="range" class="form-range weight-range" min="0" max="10" step="0.1"
                                                        id="price_weight" name="weights[Price]"
                                                        value="<?php echo $currentWeights['Price']; ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <span>0</span>
                                                        <span class="weight-value" id="price_value"><?php echo $currentWeights['Price']; ?></span>
                                                        <span>10</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3 d-flex align-items-center">
                                            <div class="total-weight w-100">
                                                <i class="fas fa-calculator me-2"></i> Total Weight:
                                                <span id="total-weight" class="ms-2">0</span>
                                                <div class="progress mt-2">
                                                    <div id="weight-progress" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <?php if (!isset($results) || !isset($upload_file)): ?>
                                    <button type="reset" class="btn btn-outline-secondary me-2">
                                        <i class="fas fa-redo me-2"></i> Reset Weights
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" id="processButton">
                                    <i class="fas fa-calculator me-2"></i> Process Data
                                </button>
                            </div>
                        </form>


                        <!-- Jika ada error dalam upload file -->
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger mt-3" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($results): ?>
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-trophy me-2"></i> Ranking Results
                    </div>
                    <div class="card-body">
                        <div class="results-info mb-4">
                            <h5 class="mb-3"><i class="fas fa-balance-scale me-2"></i> Criteria Weights Used</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-group">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Engine Size <span class="badge badge-cost">Cost</span>
                                            <span class="badge bg-primary rounded-pill"><?php echo number_format($currentWeights['Engine_Size'] * 10, 0); ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Mileage <span class="badge badge-cost">Cost</span>
                                            <span class="badge bg-primary rounded-pill"><?php echo number_format($currentWeights['Mileage'] * 10, 0); ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Doors <span class="badge badge-benefit">Benefit</span>
                                            <span class="badge bg-primary rounded-pill"><?php echo number_format($currentWeights['Doors'] * 10, 0); ?>%</span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-group">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Owner Count <span class="badge badge-cost">Cost</span>
                                            <span class="badge bg-primary rounded-pill"><?php echo number_format($currentWeights['Owner_Count'] * 10, 0); ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Price <span class="badge badge-cost">Cost</span>
                                            <span class="badge bg-primary rounded-pill"><?php echo number_format($currentWeights['Price'] * 10, 0); ?>%</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <?php
                            // Pagination setup
                            $perPage = 10;
                            $totalResults = count($results);
                            $totalPages = ceil($totalResults / $perPage);
                            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                            $start = ($page - 1) * $perPage;
                            $paginatedResults = array_slice($results, $start, $perPage);
                            ?>

                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-medal me-1"></i> Rank</th>
                                        <th>Brand</th>
                                        <th>Model</th>
                                        <th>Year</th>
                                        <th>Engine Size</th>
                                        <th>Fuel Type</th>
                                        <th>Transmission</th>
                                        <th>Mileage</th>
                                        <th>Doors</th>
                                        <th>Owner Count</th>
                                        <th>Price</th>
                                        <th><i class="fas fa-star me-1"></i> Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paginatedResults as $rank => $result):
                                        $globalRank = $start + $rank;
                                        // Trophy icons for top 3
                                        $trophy = '';
                                        if ($globalRank === 0) {
                                            $trophy = '<span class="badge bg-warning"><i class="fas fa-trophy" style="color:#FFFFFF"></i> ' . ($globalRank + 1) . '</span>';
                                        } elseif ($globalRank === 1) {
                                            $trophy = '<span class="badge bg-secondary"><i class="fas fa-trophy" style="color:#FFFFFF"></i> ' . ($globalRank + 1) . '</span>';
                                        } elseif ($globalRank === 2) {
                                            $trophy = '<span class="badge bg-danger"><i class="fas fa-trophy" style="color:#FFFFFF"></i> ' . ($globalRank + 1) . '</span>';
                                        }
                                    ?>
                                        <tr class="<?php echo ($globalRank < 3) ? 'rank-' . ($globalRank + 1) : ''; ?>">
                                            <td>
                                                <?php
                                                if ($globalRank < 3) {
                                                    echo $trophy;
                                                } else {
                                                    echo $globalRank + 1;
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['car']['Brand']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Model']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Year']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Engine_Size']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Fuel_Type']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Transmission']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Mileage']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Doors']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Owner_Count']); ?></td>
                                            <td><?php echo htmlspecialchars($result['car']['Price']); ?></td>
                                            <td class="preference-score"><?php echo number_format($result['preference_score'], 4); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <!-- Pagination controls -->
                            <nav aria-label="Ranking pagination">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item<?php if ($page <= 1) echo ' disabled'; ?>">
                                        <a class="page-link" href="?page=1" tabindex="-1">&laquo; First</a>
                                    </li>
                                    <li class="page-item<?php if ($page <= 1) echo ' disabled'; ?>">
                                        <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>" tabindex="-1">&lsaquo; Prev</a>
                                    </li>
                                    <?php
                                    // Show up to 5 page numbers
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item<?php if ($i == $page) echo ' active'; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item<?php if ($page >= $totalPages) echo ' disabled'; ?>">
                                        <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>">Next &rsaquo;</a>
                                    </li>
                                    <li class="page-item<?php if ($page >= $totalPages) echo ' disabled'; ?>">
                                        <a class="page-link" href="?page=<?php echo $totalPages; ?>">Last &raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    </div>
    <script>
        // Calculate total weight and validate inputs
        document.addEventListener('DOMContentLoaded', function() {
            const weightInputs = document.querySelectorAll('[name^="weights["]');
            const totalWeightDisplay = document.getElementById('total-weight');
            const processButton = document.getElementById('processButton');
            const weightProgress = document.getElementById('weight-progress');
            const sawForm = document.getElementById('sawForm');

            // Map input id to value span id
            const valueSpans = {
                'engine_size_weight': document.getElementById('engine_size_value'),
                'mileage_weight': document.getElementById('mileage_value'),
                'doors_weight': document.getElementById('doors_value'),
                'owner_count_weight': document.getElementById('owner_count_value'),
                'price_weight': document.getElementById('price_value')
            };

            function updateTotalWeight() {
                let total = 0;
                weightInputs.forEach(input => {
                    total += parseFloat(input.value || 0);
                    // Update corresponding value span
                    if (valueSpans[input.id]) {
                        valueSpans[input.id].textContent = input.value;
                    }
                });

                totalWeightDisplay.textContent = total.toFixed(1);

                // Progress bar update
                if (weightProgress) {
                    let percent = Math.min((total / 10) * 100, 100);
                    weightProgress.style.width = percent + '%';
                    weightProgress.classList.remove('bg-danger', 'bg-success', 'bg-warning');
                    if (Math.abs(total - 10) < 0.001) {
                        weightProgress.classList.add('bg-success');
                    } else {
                        weightProgress.classList.add('bg-danger');
                    }
                }

                // Visual feedback on total
                if (Math.abs(total - 10) < 0.001) {
                    totalWeightDisplay.style.color = 'green';
                    if (processButton) processButton.disabled = false;
                } else {
                    totalWeightDisplay.style.color = 'red';
                    if (processButton) processButton.disabled = true;
                }
            }

            // Initialize
            updateTotalWeight();

            // Update on change
            weightInputs.forEach(input => {
                input.addEventListener('input', updateTotalWeight);
            });

            // Update value spans when form is reset
            if (sawForm) {
                sawForm.addEventListener('reset', function() {
                    // Wait for the reset to actually update the input values
                    setTimeout(updateTotalWeight, 0);
                });
            }
        });
    </script>
</body>

</html>