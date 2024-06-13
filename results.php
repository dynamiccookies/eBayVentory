<?php
// Configuration for database and API
// Contains $accessToken, $servername, $username, $password, $dbname
include_once 'db_config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT transaction_id, order_id, buyer_username, transaction_type, amount_value, booking_entry, transaction_date, transaction_status, transaction_memo, payments_entity, references_json, fee_type FROM transactions ORDER BY transaction_date DESC";
$result = $conn->query($sql);
if (!$result) echo "Error: "  . $conn->error;
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Transaction Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        h1 {
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            cursor: pointer;
        }
        td.nowrap {
            white-space: nowrap;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: lightyellow;
        }
    </style>
</head>
<body>
    <h1>Transaction Data</h1>
    <table id="transactions">
        <thead>
            <tr>
                <th>Transaction Date</th>
                <th>Transaction ID</th>
                <th>Order ID</th>
                <th>Buyer Username</th>
                <th>Transaction Type</th>
                <th>Amount</th>
                <th>Booking Entry</th>
                <th>Transaction Status</th>
                <th>Transaction Memo</th>
                <th>References JSON</th>
                <th>Fee Type</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (isset($result) && ($result->num_rows > 0)) {
                while($row = $result->fetch_assoc()) {
                    $order = ($row["order_id"] ? "<a href='https://www.ebay.com/sh/ord/details?orderid=" . $row["order_id"] . "' target='_blank'>" . $row["order_id"] . "</a>" : $row["order_id"]);
                    echo "<tr>
                            <td class='nowrap'>" . $formattedDate = (new DateTime($row["transaction_date"]))->format('m/d/y h:i:s A') . "</td>
                            <td>" . $row["transaction_id"] . "</td>
                            <td class='nowrap'>" . $order . "</td>
                            <td>" . $row["buyer_username"] . "</td>
                            <td>" . $row["transaction_type"] . "</td>
                            <td>" . $row["amount_value"] . "</td>
                            <td>" . $row["booking_entry"] . "</td>
                            <td>" . $row["transaction_status"] . "</td>
                            <td>" . $row["transaction_memo"] . "</td>
                            <td>" . $row["references_json"] . "</td>
                            <td>" . $row["fee_type"] . "</td>
                        </tr>";
                }
            } else {echo "<tr><td colspan='13'>No results found</td></tr>";}
            ?>
        </tbody>
    </table>
</body>
</html>
