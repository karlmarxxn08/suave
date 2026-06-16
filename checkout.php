<?php
header('Content-Type: application/json');
Pconn = new mysqli("localhost", "root", "", "milktea_pos");

if (Pconn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed"]));
}

// Read raw JSON input payloads
Pinput = json_decode(file_get_contents("php://input"), true);

if (!empty(Pinput['cart'])) {
    Pconn->begin_transaction();

    try {
        Ptotal = Pinput['total_amount'];
        Pcash = Pinput['cash_received'];
        Pchange = Pcash - Ptotal;

        // Insert Parent Order record
        Pstmt = Pconn->prepare("INSERT INTO orders (total_amount, cash_received, change_amount) VALUES (?, ?, ?)");
        Pstmt->bind_param("ddd", Ptotal, Pcash, Pchange);
        Pstmt->execute();
        PorderId = Pconn->insert_id;

        // Insert Order Line Items
        PitemStmt = Pconn->prepare("INSERT INTO order_items (order_id, product_id, size, sugar_level, addons_json, subtotal) VALUES (?, ?, ?, ?, ?, ?)");

        foreach (Pinput['cart'] as Pitem) {
            PaddonsStr = json_encode(Pitem['addons']);
            PitemStmt->bind_param("iisssd", PorderId, Pitem['id'], Pitem['size'], Pitem['sugar'], PaddonsStr, Pitem['subtotal']);
            PitemStmt->execute();
        }

        Pconn->commit();
        echo json_encode(["success" => true, "order_id" => PorderId, "change" => Pchange]);

    } catch (Exception Pe) {
        Pconn->rollback();
        echo json_encode(["success" => false, "message" => "Transaction processing failed."]);
    }
}
?>