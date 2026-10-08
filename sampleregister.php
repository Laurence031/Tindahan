<?php
// Tiyakin na may ipinasang data mula sa form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Saluhin ang tinype ng user sa HTML form
    $bagong_user = [
        "username" => $_POST['fullname'],
        "email" => $_POST['email'],
        "password" => $_POST['password'],
        "confirm_password" => $_POST['confirm_password'],
        "gender" => $_POST['gender'],
        "birthdate" => $_POST['birthdate'],
        "contact_number" => $_POST['contact_number'],
        "address" => $_POST['address'],
        "course" => $_POST['course'],   
        "date" => date("Y-m-d")
    ];

    // 2. PALITAN MO ITO: I-paste dito ang kinopya mong URL mula sa Firebase Dashboard
    // Siguraduhing may "/users.json" sa dulo ng URL mo!
    $firebase_url = "https://dict-project-witi-default-rtdb.asia-southeast1.firebasedatabase.app/users.json";
    $jsonFile = 'users.json';
    if (file_exists($jsonFile) && filesize($jsonFile) > 0) {
    $currentData = json_decode(file_get_contents($jsonFile), true);
    // Siguraduhin na array ang nakuha, kung hindi ay i-reset sa array
    if (!is_array($currentData)) {
        $currentData = [];
    }
    } else {
    $currentData = [];
    }
     // Palitan ang $formData ng kung ano ang gamit mo sa Firebase data

    // 👇 INAYOS NA LINE ORDER: Idagdag muna ang bagong user bago i-save ang file
    $currentData[] = $bagong_user;
    file_put_contents($jsonFile, json_encode($currentData, JSON_PRETTY_PRINT));
    
    // 3. I-convert ang data natin sa JSON text format (wika ng Firebase)
    $json_data = json_encode($bagong_user, JSON_PRETTY_PRINT);
        $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $firebase_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true); // POST request para magdagdag ng bago
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // I-bypass ang SSL sa localhost
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    // 5. Patakbuhin ang cURL at kunin ang tugon ng Firebase
    $response = curl_exec($ch);
    curl_close($ch);
     

 // 6. I-check kung nagtagumpay
    if ($response) {
        echo "<h3>Success! Your Data has been submitted:) " . htmlspecialchars($bagong_user['username']) . "</h3>";
        echo "<a href='index.html'>Magrehistro ulit</a>";
    } else {
        echo "<h3>Error: Hindi makakonekta sa Firebase. I-check ang internet connection mo.</h3>";
    }
} else {
    echo "Bawal i-access nang direkta ang file na ito.";
}
?>
