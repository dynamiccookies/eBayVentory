<?php
// Configuration for database and API
// Contains $accessToken, $servername, $username, $password, $dbname
include_once 'db_config.php';

$apiUrl = "https://apiz.ebay.com/sell/finances/v1/transaction?limit=1000";

// Functions
function createTransactionsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS transactions (
        ID INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(50) NOT NULL,
        order_id VARCHAR(50),
        payout_id VARCHAR(50),
        sales_record_reference VARCHAR(50),
        buyer_username VARCHAR(100),
        transaction_type VARCHAR(50),
        amount_value DECIMAL(10, 2),
        booking_entry VARCHAR(50),
        transaction_date DATETIME,
        transaction_status VARCHAR(50),
        transaction_memo VARCHAR(255),
        payments_entity VARCHAR(100),
        references_json TEXT,
        fee_type VARCHAR(50),
        json TEXT
    )";

    if ($conn->query($sql) === TRUE) {
        echo "Table 'transactions' created successfully<br>";
    } else {
        echo "Error creating table: " . $conn->error;
        exit;
    }
}

function convert_date($iso_datetime_str, $target_timezone = 'America/Chicago') {
    // Create a DateTime object from the ISO datetime string
    $datetime = new DateTime($iso_datetime_str, new DateTimeZone($target_timezone));

    // Format the datetime and return
    return $datetime->format('Y-m-d H:i:s');
}

function transaction_exists($conn, $transaction_id, $booking_entry, $transaction_type) {
    $transaction_id = sanitize_input($conn, $transaction_id);
    $sql = "SELECT transaction_id FROM transactions WHERE transaction_id = '$transaction_id' AND booking_entry = '$booking_entry' AND transaction_type = '$transaction_type' LIMIT 1";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

function runScript() {
    global $apiUrl, $accessToken, $servername, $username, $password, $dbname;

    // Initialize cURL to call eBay API
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    } else {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode == 200) {
            $transactions = json_decode($response, true);
    
            // Create connection
            $conn = new mysqli($servername, $username, $password, $dbname);
    
            // Check connection
            if ($conn->connect_error) {
                die("Connection failed: " . $conn->connect_error);
            }
    
            // Check if transactions table exists, if not create it
            $checkTableQuery = "SHOW TABLES LIKE 'transactions'";
            $tableExists = $conn->query($checkTableQuery);
            if ($tableExists->num_rows == 0) {
                createTransactionsTable($conn);
            }

            $added = $total = 0;

            // Prepare and execute SQL INSERT statement for each transaction
            foreach ($transactions['transactions'] as $transaction) {
                $transaction_id       = isset($transaction['transactionId'])        ? $transaction['transactionId']                 : '';
                $orderId              = isset($transaction['orderId'])              ? $transaction['orderId']                       : '';
                $payoutId             = isset($transaction['payoutId'])             ? $transaction['payoutId']                      : '';
                $salesRecordReference = isset($transaction['salesRecordReference']) ? $transaction['salesRecordReference']          : '';
                $buyer_username       = isset($transaction['buyer']['username'])    ? $transaction['buyer']['username']             : '';
                $transactionType      = isset($transaction['transactionType'])      ? $transaction['transactionType']               : '';
                $amount_value         = isset($transaction['amount']['value'])      ? $transaction['amount']['value']               : '';
                $bookingEntry         = isset($transaction['bookingEntry'])         ? $transaction['bookingEntry']                  : '';
                $transactionDate      = isset($transaction['transactionDate'])      ? convert_date($transaction['transactionDate']) : '';
                $transactionStatus    = isset($transaction['transactionStatus'])    ? $transaction['transactionStatus']             : '';
                $transactionMemo      = isset($transaction['transactionMemo'])      ? $transaction['transactionMemo']               : '';
                $paymentsEntity       = isset($transaction['paymentsEntity'])       ? $transaction['paymentsEntity']                : '';
                $references           = isset($transaction['references'])           ? json_encode($transaction['references'])       : '';
                $feeType              = isset($transaction['feeType'])              ? $transaction['feeType']                       : '';
                $transaction          = json_encode($transaction);


                // Check if transaction already exists
                if (!transaction_exists($conn, $transaction_id, $bookingEntry, $transactionType)) {
                    // Sanitize the data and insert the transaction into the database
                    $sql = "INSERT INTO transactions (transaction_id, order_id, payout_id, sales_record_reference, buyer_username, transaction_type, amount_value, booking_entry, transaction_date, transaction_status, transaction_memo, payments_entity, references_json, fee_type, json) 
                            VALUES ('$transaction_id', '$orderId', '$payoutId', '$salesRecordReference', '$buyer_username', '$transactionType', '$amount_value', '$bookingEntry', '$transactionDate', '$transactionStatus', '$transactionMemo', '$paymentsEntity', '$references', '$feeType', '$transaction')";
                    $sql = $conn->real_escape_string($sql);

                    if ($conn->query($sql) === TRUE) {$added += 1;} 
					else {echo "Error: " . $sql . "<br>" . $conn->error;}
                }
                
                $total += 1;
            }
            $conn->close();
            
            echo $added . ' out of ' . $total . ' transactions added.';
            
        } else {
            echo "Failed to fetch transactions. HTTP Status Code: " . $httpCode;
        }
    }
    
curl_close($ch);
}

// Check if running via CLI for cron job
$isCli = php_sapi_name() === 'cli';

// If running via CLI (cron job) or manually initiated, execute the script
if ($isCli || isset($_POST['manual_execution'])) {
    runScript();
} else {
    // If not running via CLI or not initiated manually, output message
    echo "This script can only be run via a cron job or manually initiated.";
}

?>
