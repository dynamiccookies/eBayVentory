<?php
// Configuration for database and API
// Contains $accessToken, $servername, $username, $password, $dbname
include_once 'db_config.php';

$apiUrl = "https://apiz.ebay.com/sell/finances/v1/transaction?limit=1000";

// Functions
/**
 * Creates the 'transactions' table in the database if it does not already exist.
 * The table includes various fields to store transaction-related information.
 *
 * @param mysqli $conn The database connection object.
 */
function createTransactionsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS transactions (
        ID                     INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_id         VARCHAR(50) NOT NULL,
        order_id               VARCHAR(50),
        payout_id              VARCHAR(50),
        sales_record_reference VARCHAR(50),
        buyer_username         VARCHAR(100),
        transaction_type       VARCHAR(50),
        amount_value           DECIMAL(10, 2),
        booking_entry          VARCHAR(50),
        transaction_date       DATETIME,
        transaction_status     VARCHAR(50),
        transaction_memo       VARCHAR(255),
        payments_entity        VARCHAR(100),
        references_json        TEXT,
        fee_type               VARCHAR(50),
        json                   TEXT
    )";

    if ($conn->query($sql) === TRUE) {
        echo "Table 'transactions' created successfully<br>";
    } else {
        echo "Error creating table: " . $conn->error;
        exit;
    }
}

/**
 * Converts an ISO datetime string to a specified timezone and formats it to 'Y-m-d H:i:s'.
 *
 * @param string $iso_datetime_str The ISO datetime string to be converted.
 * @param string $target_timezone The target timezone for conversion. Default is 'America/Chicago'.
 * @return string The formatted datetime string.
 */
function convert_date($iso_datetime_str, $target_timezone = 'America/Chicago') {
    // Create a DateTime object from the ISO datetime string
    $datetime = new DateTime($iso_datetime_str, new DateTimeZone($target_timezone));

    // Format the datetime and return
    return $datetime->format('Y-m-d H:i:s');
}

/**
 * Sanitizes input to prevent SQL injection by escaping special characters.
 *
 * @param mysqli $conn The database connection object.
 * @param string $input The input string to be sanitized.
 * @return string The sanitized input string.
 */
function sanitize_input($conn, $input) {
    return $conn->real_escape_string($input);
}

/**
 * Checks if a transaction with the specified transaction ID, booking entry, and transaction type exists in the 'transactions' table.
 *
 * @param mysqli $conn The database connection object.
 * @param string $transaction_id The transaction ID to check.
 * @param string $booking_entry The booking entry to check.
 * @param string $transaction_type The transaction type to check.
 * @return bool True if the transaction exists, false otherwise.
 */
function transaction_exists($conn, $transaction_id, $booking_entry, $transaction_type) {
    $transaction_id = sanitize_input($conn, $transaction_id);
    $sql = "SELECT transaction_id FROM transactions WHERE transaction_id = '$transaction_id' AND booking_entry = '$booking_entry' AND transaction_type = '$transaction_type' LIMIT 1";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}


/**
 * Executes the main script to fetch transaction data from the eBay API and update the local database.
 * The script performs the following steps:
 * 1. Initializes a cURL session to call the eBay API.
 * 2. Parses the API response containing transaction data.
 * 3. Connects to the MySQL database.
 * 4. Checks if the 'transactions' table exists and creates it if necessary.
 * 5. Iterates over each transaction in the API response:
 *    a. Sanitizes input data.
 *    b. Checks if the transaction already exists in the database.
 *    c. Inserts new transactions or updates existing ones if necessary.
 * 6. Closes the database connection and outputs the result.
 */
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

            $added = $updated = $total = 0;

            // Prepare and execute SQL INSERT statement for each transaction
            foreach ($transactions['transactions'] as $transaction) {
                $transaction_id       = isset($transaction['transactionId'])        ? sanitize_input($conn, $transaction['transactionId'])           : '';
                $orderId              = isset($transaction['orderId'])              ? sanitize_input($conn, $transaction['orderId'])                 : '';
                $payoutId             = isset($transaction['payoutId'])             ? sanitize_input($conn, $transaction['payoutId'])                : '';
                $salesRecordReference = isset($transaction['salesRecordReference']) ? sanitize_input($conn, $transaction['salesRecordReference'])    : '';
                $buyer_username       = isset($transaction['buyer']['username'])    ? sanitize_input($conn, $transaction['buyer']['username'])       : '';
                $transactionType      = isset($transaction['transactionType'])      ? sanitize_input($conn, $transaction['transactionType'])         : '';
                $amount_value         = isset($transaction['amount']['value'])      ? sanitize_input($conn, $transaction['amount']['value'])         : '';
                $bookingEntry         = isset($transaction['bookingEntry'])         ? sanitize_input($conn, $transaction['bookingEntry'])            : '';
                $transactionDate      = isset($transaction['transactionDate'])      ? convert_date($transaction['transactionDate'])                  : '';
                $transactionStatus    = isset($transaction['transactionStatus'])    ? sanitize_input($conn, $transaction['transactionStatus'])       : '';
                $transactionMemo      = isset($transaction['transactionMemo'])      ? sanitize_input($conn, $transaction['transactionMemo'])         : '';
                $paymentsEntity       = isset($transaction['paymentsEntity'])       ? sanitize_input($conn, $transaction['paymentsEntity'])          : '';
                $references           = isset($transaction['references'])           ? sanitize_input($conn, json_encode($transaction['references'])) : '';
                $feeType              = isset($transaction['feeType'])              ? sanitize_input($conn, $transaction['feeType'])                 : '';
                $transaction          = sanitize_input($conn, json_encode($transaction));

                // Check if orderId is blank and references field exists
                if (empty($orderId) && isset($transaction['references'])) {
                    $referencesArray = json_decode($transaction['references'], true);
                    if (is_array($referencesArray)) {
                        foreach ($referencesArray as $reference) {
                            if (isset($reference['referenceType']) && $reference['referenceType'] === 'ORDER_ID') {
                                $orderId = sanitize_input($conn, $reference['referenceId']);
                                break;
                            }
                        }
                    }
                }

                // Check if transaction already exists
                if (!transaction_exists($conn, $transaction_id, $bookingEntry, $transactionType)) {
                    // Sanitize the data and insert the transaction into the database
                    $sql = "INSERT INTO transactions (transaction_id, order_id, payout_id, sales_record_reference, buyer_username, transaction_type, amount_value, booking_entry, transaction_date, transaction_status, transaction_memo, payments_entity, references_json, fee_type, json) 
                            VALUES ('$transaction_id', '$orderId', '$payoutId', '$salesRecordReference', '$buyer_username', '$transactionType', '$amount_value', '$bookingEntry', '$transactionDate', '$transactionStatus', '$transactionMemo', '$paymentsEntity', '$references', '$feeType', '$transaction')";

                    if ($conn->query($sql) === TRUE) {$added += 1;}
                    else {echo "Error Adding Record: " . $sql . "<br>" . $conn->error;}
                } else {
                    // Fetch the current orderId and transactionStatus from the database
                    $sql                      = "SELECT order_id, transaction_status FROM transactions WHERE transaction_id = '$transaction_id' AND booking_entry = '$bookingEntry' AND transaction_type = '$transactionType'";
                    $result                   = $conn->query($sql);
                    $row                      = $result->fetch_assoc();
                    $currentOrderId           = $row['order_id'];
                    $currentTransactionStatus = $row['transaction_status'];

                    // Check if orderId or transactionStatus are different
                    if ($currentOrderId != $orderId || $currentTransactionStatus != $transactionStatus) {
                        // Update all fields
                        $updateSql = "UPDATE transactions SET 
                            order_id               = '$orderId', 
                            payout_id              = '$payoutId', 
                            sales_record_reference = '$salesRecordReference', 
                            buyer_username         = '$buyer_username', 
                            transaction_type       = '$transactionType', 
                            amount_value           = '$amount_value', 
                            booking_entry          = '$bookingEntry', 
                            transaction_date       = '$transactionDate', 
                            transaction_status     = '$transactionStatus', 
                            transaction_memo       = '$transactionMemo', 
                            payments_entity        = '$paymentsEntity', 
                            references_json        = '$references', 
                            fee_type               = '$feeType', 
                            json                   = '$transaction' 
                            WHERE transaction_id   = '$transaction_id' AND booking_entry = '$bookingEntry' AND transaction_type = '$transactionType'";

                        if ($conn->query($updateSql) === TRUE) {$updated += 1;}
                        else {echo "Error Updating Record: " . $sql . "<br>" . $conn->error;}
                    }
                }
                $total += 1;
            }
            $conn->close();
            
            echo $added . ' out of ' . $total . ' transactions added. ';
            if ($updated > 0) $updated . ' transactions updated.';

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
