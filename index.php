<?php
// ==========================================
// 1. BACKEND DATABASE HANDLING & ROUTER
// ==========================================
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "milktea_pos";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Current View Mode Router state switcher (Defaults to 'welcome' if no view parameter is set)
$current_view = isset($_GET['view']) ? $_GET['view'] : 'welcome';

// --- ADMIN ACTION: Reset Dashboard Sales Stats & Refresh Order ID to 1 ---
if (isset($_GET['action']) && $_GET['action'] === 'reset_counters') {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("TRUNCATE TABLE order_items");
    $conn->query("TRUNCATE TABLE orders");
    $conn->query("ALTER TABLE orders AUTO_INCREMENT = 1");
    $conn->query("ALTER TABLE order_items AUTO_INCREMENT = 1");
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    
    header("Location: index.php?view=admin&reset_success=1");
    exit;
}

// --- ADMIN ACTION: Create/Insert Menu Product ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $p_name = trim($_POST['prod_name']);
    $p_price = floatval($_POST['prod_price']);
    $p_cat = trim($_POST['prod_cat']);
    
    if (!empty($p_name) && $p_price > 0) {
        $addStmt = $conn->prepare("INSERT INTO products (name, base_price, category) VALUES (?, ?, ?)");
        if ($addStmt === false) {
            die("SQL Prepare Error: " . $conn->error);
        }
        $addStmt->bind_param("sds", $p_name, $p_price, $p_cat);
        $addStmt->execute();
        header("Location: index.php?view=admin"); 
        exit;
    }
}

// --- ADMIN ACTION: Delete Menu Product ---
if (isset($_GET['action']) && $_GET['action'] === 'delete_product' && isset($_GET['id'])) {
    $p_id = intval($_GET['id']);
    $delStmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    if ($delStmt === false) {
        die("SQL Prepare Error: " . $conn->error);
    }
    $delStmt->bind_param("i", $p_id);
    $delStmt->execute();
    header("Location: index.php?view=admin");
    exit;
}

// --- CASHIER ACTION: Complete/Serve a Pending Order ---
if (isset($_GET['action']) && $_GET['action'] === 'complete_pending' && isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    
    $custQuery = $conn->query("SELECT customer_name FROM orders WHERE id = $order_id");
    $custRow = $custQuery->fetch_assoc();
    $c_name = $custRow['customer_name'] ?? 'Walk-in';

    $compStmt = $conn->prepare("UPDATE orders SET status = 'Completed', cash_received = IF(cash_received > 0, cash_received, total_amount), change_amount = IF(cash_received > 0, change_amount, 0.00) WHERE id = ?");
    if ($compStmt === false) {
        die("SQL Prepare Error: " . $conn->error);
    }
    $compStmt->bind_param("i", $order_id);
    $compStmt->execute();
    
    header("Location: index.php?view=cashier&served=1&id=" . $order_id . "&customer=" . urlencode($c_name));
    exit;
}

// --- AJAX INTERACTION: Process Order Checkout ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'checkout') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents("php://input"), true);

    if (empty($input['cart'])) {
        echo json_encode(["success" => false, "message" => "Your cart is empty!"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $total = $input['total_amount'];
        $is_pending = isset($input['is_pending']) && $input['is_pending'] === true;
        
        $cash = $is_pending ? 0.00 : $input['cash_received'];
        $customer_name = !empty($input['customer_name']) ? trim($input['customer_name']) : 'Walk-in';
        
        $status = 'Pending'; 
        $change = $is_pending ? 0.00 : ($cash - $total);

        $stmt = $conn->prepare("INSERT INTO orders (customer_name, status, total_amount, cash_received, change_amount) VALUES (?, ?, ?, ?, ?)");
        if ($stmt === false) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param("ssddd", $customer_name, $status, $total, $cash, $change);
        $stmt->execute();
        $orderId = $conn->insert_id;

        $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, size, sugar_level, addons_json, subtotal) VALUES (?, ?, ?, '100%', '[]', ?)");
        if ($itemStmt === false) {
            throw new Exception($conn->error);
        }
        
        foreach ($input['cart'] as $item) {
            $singleUnitCost = $item['subtotal'] / $item['quantity'];
            for ($i = 0; $i < $item['quantity']; $i++) {
                $itemStmt->bind_param("iisd", $orderId, $item['id'], $item['size'], $singleUnitCost);
                $itemStmt->execute();
            }
        }

        $conn->commit();
        echo json_encode([
            "success" => true, 
            "order_id" => $orderId, 
            "customer" => $customer_name, 
            "status" => 'Pending',
            "change" => number_format($change, 2)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
    exit;
}

$products = [];
$categorized_products = [];
$res_prod = $conn->query("SELECT * FROM products ORDER BY category ASC, name ASC");
if ($res_prod) {
    while ($row = $res_prod->fetch_assoc()) { 
        $products[] = $row; 
        $categorized_products[$row['category']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suave - Cafe Premium POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans min-h-screen flex flex-col overflow-x-hidden relative">

    <!-- High-Visibility Dynamic Fullscreen Watermark Background Overlay -->
    <div class="fixed inset-0 pointer-events-none z-0 flex items-center justify-center opacity-60">
        <img src="images/suavelogo_2.jpg" alt="Suave Watermark Background" class="w-full max-w-2xl object-contain select-none">
    </div>

    <?php if ($current_view === 'welcome'): ?>
        <!-- ==========================================
             GET STARTED WELCOME UI SCREEN
             ========================================== -->
        <div class="flex-1 flex flex-col items-center justify-center p-6 z-10 relative">
            <div class="bg-white/80 backdrop-blur-md max-w-xl w-full rounded-3xl p-8 shadow-2xl border border-slate-200 text-center flex flex-col items-center space-y-6">
                <!-- Brand Logo Display -->
                <div class="p-2 bg-white rounded-2xl shadow-md max-w-[240px]">
                    <img src="images/suavelogo_2.jpg" alt="Kape Suave Logo" class="w-full h-auto object-contain rounded-xl">
                </div>

                <!-- Welcome Text Block -->
                <div class="space-y-1">
                    <h2 class="text-3xl font-black tracking-tight text-[#051138]">Premium POS Terminal</h2>
                    <p class="text-sm font-medium text-slate-500 italic">"Stay cool, Stay Suave."</p>
                </div>

                <hr class="w-full border-slate-200">

                <!-- Quick Snapshot Counter Information -->
                <div class="grid grid-cols-2 gap-4 w-full">
                    <div class="bg-[#051138]/5 p-4 rounded-2xl border border-[#051138]/10 text-center">
                        <span class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Active Menu Items</span>
                        <span class="text-2xl font-black text-[#051138]"><?php echo count($products); ?> Varieties</span>
                    </div>
                    <div class="bg-emerald-600/5 p-4 rounded-2xl border border-emerald-600/10 text-center">
                        <span class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Orders Today</span>
                        <span class="text-2xl font-black text-emerald-700">
                            <?php $cnt_ord = $conn->query("SELECT COUNT(id) FROM orders"); echo $cnt_ord ? $cnt_ord->fetch_row()[0] : 0; ?> Active
                        </span>
                    </div>
                </div>

                <!-- Launch / Get Started CTA Button -->
                <a href="index.php?view=cashier" class="w-full bg-[#051138] hover:bg-indigo-900 text-white font-black py-4 px-6 rounded-2xl shadow-xl transition-all duration-200 transform hover:-translate-y-0.5 text-center uppercase tracking-widest text-sm flex items-center justify-center space-x-2 group">
                    <span>Launch POS Terminal</span>
                    <span class="transition-transform group-hover:translate-x-1">➔</span>
                </a>

                <div class="flex space-x-4 text-xs font-semibold text-slate-400">
                    <a href="index.php?view=history" class="hover:text-cyan-700 transition">View History Logs</a>
                    <span>•</span>
                    <a href="index.php?view=admin" class="hover:text-amber-600 transition">System Management</a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ==========================================
             MAIN APPLICATION WORKSPACE TERMINALS
             ========================================== -->
        <!-- Static Sticky Navigation Header -->
        <header class="sticky top-0 bg-[#051138] text-white shadow-xl px-6 py-3 flex justify-between items-center z-50">
            <div class="flex items-center space-x-4">
                <a href="index.php?view=welcome" class="bg-white p-1 rounded-xl shadow-md border border-slate-700 flex items-center justify-center hover:opacity-90 transition">
                    <img src="images/suavelogo_2.jpg" alt="Suave Logo" class="h-10 w-10 object-contain rounded-lg">
                </a>
                <div>
                    <h1 class="text-xl font-black tracking-widest leading-none text-slate-100">SUAVE</h1>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full inline-block mt-1 <?php echo $current_view === 'admin' ? 'bg-amber-500 text-amber-950' : ($current_view === 'history' ? 'bg-cyan-500 text-cyan-950' : 'bg-emerald-500 text-emerald-950'); ?>">
                        ● <?php echo strtoupper($current_view); ?> TERMINAL
                    </span>
                </div>
            </div>
            <nav class="flex space-x-2">
                <a href="index.php?view=cashier" class="px-5 py-2 rounded-xl text-sm font-bold transition <?php echo $current_view === 'cashier' ? 'bg-indigo-950 text-white shadow-md' : 'text-slate-300 hover:bg-indigo-950/60'; ?>">
                    🛒 Terminal Board
                </a>
                <a href="index.php?view=history" class="px-5 py-2 rounded-xl text-sm font-bold transition <?php echo $current_view === 'history' ? 'bg-cyan-700 text-white shadow-md' : 'text-slate-300 hover:bg-indigo-950/60'; ?>">
                    📜 Logs Archive
                </a>
                <a href="index.php?view=admin" class="px-5 py-2 rounded-xl text-sm font-bold transition <?php echo $current_view === 'admin' ? 'bg-amber-600 text-white shadow-md' : 'text-slate-300 hover:bg-indigo-950/60'; ?>">
                    📊 Management
                </a>
            </nav>
        </header>

        <main class="flex-1 w-full mx-auto p-4 flex flex-col space-y-4 z-10 relative">
            
            <?php if ($current_view === 'cashier' && isset($_GET['served']) && isset($_GET['customer']) && isset($_GET['id'])): ?>
                <div class="bg-emerald-50/90 border-l-4 border-emerald-600 text-emerald-950 p-3 rounded-xl shadow-sm flex items-center justify-between transition-all">
                    <div class="flex items-center space-x-3">
                        <span class="text-lg">✅</span>
                        <p class="text-sm font-semibold">Served Order #<?php echo intval($_GET['id']); ?> for <span class="underline font-bold"><?php echo htmlspecialchars($_GET['customer']); ?></span> successfully!</p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900 font-bold text-sm px-2">✕</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['reset_success'])): ?>
                <div class="bg-blue-50/90 border-l-4 border-blue-600 text-blue-950 p-3 rounded-xl shadow-sm flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <span class="text-lg">🔄</span>
                        <p class="text-sm font-semibold">Dashboard counters cleared. Next Order ID sequence has been reset back to #1.</p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-blue-600 hover:text-blue-800 font-bold text-sm px-2">✕</button>
                </div>
            <?php endif; ?>

            <?php if ($current_view === 'admin'): 
                $sales_query = $conn->query("SELECT SUM(total_amount) as gross, COUNT(id) as total_orders FROM orders WHERE status = 'Completed'");
                $sales_stat = $sales_query ? $sales_query->fetch_assoc() : null;
                $gross_revenue = $sales_stat['gross'] ?? 0;
                $order_count = $sales_stat['total_orders'] ?? 0;
            ?>
                <div class="flex flex-col space-y-4">
                    <div class="flex justify-between items-center bg-slate-200/40 backdrop-blur-sm p-4 rounded-xl border border-slate-300 shadow-sm">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800">📊 Sales Calculator Metric Summaries</h2>
                            <p class="text-xs text-slate-500">Live operational metrics and transaction resets.</p>
                        </div>
                        <a href="index.php?action=reset_counters" 
                           onclick="return confirm('⚠️ CRITICAL WARNING: This will permanently wipe ALL transaction histories and completely refresh the Order ID sequence back to #1. Are you sure you want to proceed?')"
                           class="bg-red-600 hover:bg-red-700 text-white font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl shadow transition">
                            🔄 Wipe Stats & Reset ID to 1
                        </a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-[#051138]/80 backdrop-blur-md text-white p-6 rounded-2xl shadow-md border border-slate-800">
                            <p class="text-sm font-medium text-slate-300 tracking-wider">Gross Sales Revenue (Completed Orders)</p>
                            <h3 class="text-4xl font-black mt-1 text-slate-50">₱<?php echo number_format($gross_revenue, 2); ?></h3>
                        </div>
                        <div class="bg-gradient-to-br from-amber-500/90 to-orange-600/90 text-white p-6 rounded-2xl shadow-md">
                            <p class="text-sm font-medium text-amber-100 tracking-wider">Total Finalized Orders</p>
                            <h3 class="text-4xl font-black mt-1"><?php echo $order_count; ?> Closed Receipts</h3>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mt-2">
                    <div class="lg:col-span-4 bg-slate-200/40 backdrop-blur-sm p-5 rounded-2xl shadow-sm border border-slate-300 h-fit">
                        <h3 class="text-md font-bold text-slate-800 mb-3 flex items-center">➕ Catalog Creator</h3>
                        <form method="POST" action="index.php?view=admin" class="space-y-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Product Drink Label</label>
                                <input type="text" name="prod_name" placeholder="e.g., Mango Cheesecake" required 
                                       class="w-full border rounded-xl p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#051138]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Base Serving Cost (₱)</label>
                                <input type="number" name="prod_price" step="0.01" min="0.1" placeholder="120.00" required 
                                       class="w-full border rounded-xl p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#051138]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Menu Category Classification</label>
                                <select name="prod_cat" class="w-full border rounded-xl p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#051138]">
                                    <option value="Coffee Based">Coffee Based</option>
                                    <option value="Non-Coffee Drinks">Non-Coffee Drinks</option>
                                    <option value="Matcha Series">Matcha Series</option>
                                    <option value="Fruit Soda">Fruit Soda</option>
                                </select>
                            </div>
                            <button type="submit" name="add_product" class="w-full bg-[#051138] hover:bg-indigo-900 text-white font-bold py-2.5 px-4 rounded-xl shadow transition text-sm">
                                Save Product to Live Menu
                            </button>
                        </form>
                    </div>

                    <div class="lg:col-span-8 bg-slate-200/40 backdrop-blur-sm p-5 rounded-2xl shadow-sm border border-slate-300 overflow-x-auto">
                        <h3 class="text-md font-bold text-slate-800 mb-3">📜 Product Catalog Database</h3>
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200/60 text-slate-600 text-xs font-bold uppercase tracking-wider border-b border-slate-300">
                                    <th class="p-2.5">Drink ID</th>
                                    <th class="p-2.5">Flavor Label</th>
                                    <th class="p-2.5">Category</th>
                                    <th class="p-2.5">Base Price</th>
                                    <th class="p-2.5 text-right">Commands</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-300 text-slate-700">
                                <?php if(empty($products)): ?>
                                    <tr><td colspan="5" class="text-center p-4 text-slate-400">No active products found inside the registry.</td></tr>
                                <?php else: ?>
                                    <?php foreach($products as $p): ?>
                                        <tr class="hover:bg-slate-200/40 transition">
                                            <td class="p-2.5 font-mono font-bold text-slate-400">#<?php echo $p['id']; ?></td>
                                            <td class="p-2.5 font-bold text-slate-800"><?php echo $p['name']; ?></td>
                                            <td class="p-2.5"><span class="bg-white text-slate-700 text-xs px-2 py-0.5 rounded border border-slate-300"><?php echo $p['category']; ?></span></td>
                                            <td class="p-2.5 font-bold text-[#051138]">₱<?php echo number_format($p['base_price'], 2); ?></td>
                                            <td class="p-2.5 text-right">
                                                <a href="index.php?action=delete_product&id=<?php echo $p['id']; ?>" 
                                                   onclick="return confirm('Permanently wipe this product from the customer list?')"
                                                   class="text-red-600 hover:text-red-800 font-bold bg-red-50 hover:bg-red-100 px-2.5 py-1 rounded-lg transition text-xs">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($current_view === 'history'): ?>
                <div class="bg-slate-200/40 backdrop-blur-sm p-5 rounded-2xl shadow-sm border border-slate-300 overflow-x-auto">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800">📜 Completed Order History</h2>
                            <p class="text-xs text-slate-500 mt-0.5">Archived logs of served transactions and historical sales receipts.</p>
                        </div>
                        <span class="bg-cyan-50 text-cyan-800 text-xs font-bold px-3 py-1 rounded-full border border-cyan-200">
                            Total Archived: <?php $cnt_q = $conn->query("SELECT COUNT(id) FROM orders WHERE status='Completed'"); echo $cnt_q ? $cnt_q->fetch_row()[0] : 0; ?>
                        </span>
                    </div>

                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-200/60 text-slate-600 text-xs font-bold uppercase tracking-wider border-b border-slate-300">
                                <th class="p-2.5">Order ID</th>
                                <th class="p-2.5">Timestamp</th>
                                <th class="p-2.5">Customer Name</th>
                                <th class="p-2.5">Items Purchased</th>
                                <th class="p-2.5">Total Due</th>
                                <th class="p-2.5">Cash Given</th>
                                <th class="p-2.5">Change Amount</th>
                                <th class="p-2.5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-300 text-slate-700">
                            <?php 
                            $history_query = $conn->query("SELECT * FROM orders WHERE status = 'Completed' ORDER BY id DESC");
                            if (!$history_query || $history_query->num_rows === 0):
                            ?>
                                <tr><td colspan="8" class="text-center p-8 text-slate-400">No served history records found. Finished order receipts will populate here automatically!</td></tr>
                            <?php else: 
                                while($h_order = $history_query->fetch_assoc()):
                                    $h_id = $h_order['id'];
                                    $h_items = $conn->query("SELECT COUNT(oi.id) as qty, p.name, oi.size FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = $h_id GROUP BY oi.product_id, oi.size");
                                    $items_list = [];
                                    if($h_items) {
                                        while($i_row = $h_items->fetch_assoc()) {
                                            $items_list[] = $i_row['qty'] . "x " . $i_row['name'] . " (" . $i_row['size'] . ")";
                                        }
                                    }
                            ?>
                                <tr class="hover:bg-slate-200/40 transition">
                                    <td class="p-2.5 font-mono font-bold text-slate-500">#<?php echo $h_id; ?></td>
                                    <td class="p-2.5 text-xs text-slate-400"><?php echo date("Y-m-d h:i A", strtotime($h_order['created_at'])); ?></td>
                                    <td class="p-2.5 font-bold text-slate-800"><?php echo htmlspecialchars($h_order['customer_name']); ?></td>
                                    <td class="p-2.5 text-xs max-w-xs truncate" title="<?php echo htmlspecialchars(implode(', ', $items_list)); ?>">
                                        <?php echo implode(', ', $items_list); ?>
                                    </td>
                                    <td class="p-2.5 font-bold text-[#051138]">₱<?php echo number_format($h_order['total_amount'], 2); ?></td>
                                    <td class="p-2.5 text-slate-600">₱<?php echo number_format($h_order['cash_received'], 2); ?></td>
                                    <td class="p-2.5 text-green-600 font-medium">₱<?php echo number_format($h_order['change_amount'], 2); ?></td>
                                    <td class="p-2.5 text-center">
                                        <span class="bg-emerald-50 text-emerald-800 text-[10px] font-extrabold px-2.5 py-1 rounded-full uppercase tracking-wider border border-emerald-200">Served</span>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start w-full">
                    
                    <!-- Categorized Menu Card Container -->
                    <section class="lg:col-span-7 flex flex-col bg-slate-200/40 backdrop-blur-sm rounded-2xl shadow-sm p-4 border border-slate-300 min-h-[74vh] max-h-[74vh]">
                        <div class="flex justify-between items-center mb-3 border-b border-slate-300 pb-2 flex-shrink-0">
                            <h2 class="text-lg font-black text-slate-800 uppercase tracking-tight">Suave Menu Board</h2>
                            <span class="text-xs font-semibold bg-white text-slate-600 px-2.5 py-1 rounded-md border border-slate-300">Total Varieties: <?php echo count($products); ?></span>
                        </div>
                        
                        <!-- Scrollable Container containing distinct Category Groups -->
                        <div class="overflow-y-auto pr-1 flex-1 space-y-6">
                            <?php if (empty($categorized_products)): ?>
                                <div class="text-center py-12">
                                    <p class="text-slate-400 mb-2">No menu catalog items created yet.</p>
                                    <a href="index.php?view=admin" class="text-xs bg-[#051138] text-white font-bold px-4 py-2 rounded-lg">Go Add Menu Products</a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($categorized_products as $category_name => $prod_list): ?>
                                    <div class="space-y-2">
                                        <!-- Dynamic Section Category Title Badge Header -->
                                        <div class="flex items-center space-x-2">
                                            <h3 class="text-xs font-black uppercase text-indigo-950 tracking-wider bg-white px-3 py-1 rounded-lg border border-slate-300 shadow-sm">
                                                ☕ <?php echo htmlspecialchars($category_name); ?>
                                            </h3>
                                            <div class="h-[1px] bg-slate-300 flex-1"></div>
                                        </div>
                                        
                                        <!-- Inner Product Grid under this current Category loop -->
                                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
                                            <?php foreach ($prod_list as $product): ?>
                                                <button onclick="openCustomizer(<?php echo htmlspecialchars(json_encode($product)); ?>)" 
                                                        class="bg-white/60 hover:bg-white/90 transition border border-slate-300 p-3 rounded-xl flex flex-col justify-between text-left h-24 shadow-sm hover:shadow-md relative group">
                                                    <div class="w-full">
                                                        <h4 class="font-bold text-slate-800 text-xs group-hover:text-[#051138] transition line-clamp-2 leading-tight"><?php echo $product['name']; ?></h4>
                                                    </div>
                                                    <div class="w-full flex justify-between items-end mt-1">
                                                        <span class="text-[#051138] font-black text-sm">₱<?php echo number_format($product['base_price'], 2); ?></span>
                                                        <span class="bg-white rounded-full p-1 text-xs shadow-sm border border-slate-200 group-hover:bg-[#051138] group-hover:text-white transition">➕</span>
                                                    </div>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Transparent Active Receipt Container -->
                    <section class="lg:col-span-5 flex flex-col bg-slate-200/40 backdrop-blur-sm rounded-2xl shadow-sm p-4 border border-slate-300 min-h-[74vh] max-h-[74vh]">
                        <div class="flex justify-between items-center mb-2 border-b border-slate-300 pb-2">
                            <h2 class="text-lg font-black text-slate-800 uppercase tracking-tight">Active Bill Receipt</h2>
                            <button onclick="clearCart()" class="text-xs text-red-500 hover:underline font-bold bg-red-50 px-2 py-1 rounded border border-red-200">Clear All</button>
                        </div>

                        <div id="cart-container" class="flex-1 overflow-y-auto space-y-2 pr-1 min-h-[220px] max-h-[220px] border-b border-slate-300 pb-2 flex flex-col">
                            <div id="empty-cart-msg" class="text-center text-slate-400 my-auto pt-16">
                                <span class="text-3xl block mb-1">🛒</span> Cart Empty
                            </div>
                        </div>

                        <div class="pt-2 space-y-2.5">
                            <div class="flex justify-between font-black text-xl text-slate-900 px-1">
                                <span>Total Due:</span>
                                <span id="summary-total" class="text-[#051138]">₱0.00</span>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-white/60 p-2 rounded-xl border border-slate-300">
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-0.5">Customer Name</label>
                                    <input type="text" id="customer-name-input" placeholder="Karl / Walk-in" 
                                           class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-[#051138] focus:outline-none">
                                </div>

                                <div class="bg-white/60 p-2 rounded-xl border border-slate-300">
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-0.5">Cash Received (₱)</label>
                                    <input type="number" id="cash-input" oninput="calculateChange()" step="0.01" min="0" placeholder="0.00" 
                                           class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1 text-md font-black text-slate-800 focus:ring-2 focus:ring-[#051138] focus:outline-none">
                                </div>
                            </div>

                            <div class="flex justify-between items-center text-xs px-2 py-1 bg-white/60 rounded-lg border border-slate-300 border-dashed">
                                <span class="font-bold text-slate-600">Change Return Balance:</span>
                                <span id="change-display" class="font-black text-base text-green-600">₱0.00</span>
                            </div>

                            <div class="grid grid-cols-3 gap-2 pt-1">
                                <button onclick="submitOrder(true)" class="col-span-1 bg-amber-500 hover:bg-amber-600 text-amber-950 font-black py-3 rounded-xl text-xs uppercase tracking-wider transition shadow">
                                    ⏳ Hold
                                </button>
                                <button onclick="submitOrder(false)" class="col-span-2 bg-[#051138] hover:bg-indigo-900 text-white font-black py-3 rounded-xl text-xs uppercase tracking-wider transition shadow">
                                    💵 Cash Checkout
                                </button>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Assembly Monitor Queue -->
                <section class="bg-slate-200/40 backdrop-blur-sm rounded-2xl shadow-sm border border-slate-300 p-4 mt-2">
                    <h3 class="text-xs font-black text-amber-700 uppercase tracking-wider mb-3 flex items-center">
                        🕒 Active Pending Prep Tickets Queue Monitor
                        <?php 
                            $count_pend = $conn->query("SELECT COUNT(id) as count FROM orders WHERE status='Pending'")->fetch_assoc();
                            if($count_pend && $count_pend['count'] > 0) {
                                echo '<span class="ml-2 bg-amber-100 text-amber-800 text-xs px-2 py-0.5 rounded-full font-black border border-amber-200">'.$count_pend['count'].'</span>';
                            }
                        ?>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <?php
                        $pending_orders = $conn->query("SELECT * FROM orders WHERE status = 'Pending' ORDER BY id ASC");
                        if (!$pending_orders || $pending_orders->num_rows === 0):
                        ?>
                            <p class="text-xs text-slate-400 col-span-full py-4 text-center border rounded-xl border-dashed border-slate-300">No tickets currently waiting inside the line assembly monitor queue.</p>
                        <?php else: 
                            while($order = $pending_orders->fetch_assoc()):
                                $oid = $order['id'];
                                $items_res = $conn->query("SELECT COUNT(oi.id) as qty, p.name as prod_name, oi.size FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = $oid GROUP BY oi.product_id, oi.size");
                                $isPaid = floatval($order['cash_received']) > 0;
                        ?>
                            <div class="border <?php echo $isPaid ? 'border-emerald-300 bg-emerald-50/60' : 'border-amber-300 bg-amber-50/60'; ?> p-3 rounded-xl flex flex-col justify-between space-y-3 shadow-sm transition hover:shadow-md">
                                <div>
                                    <div class="flex justify-between items-center border-b pb-1 border-dashed <?php echo $isPaid ? 'border-emerald-300' : 'border-amber-300'; ?>">
                                        <span class="font-mono font-bold text-[11px] <?php echo $isPaid ? 'text-emerald-900' : 'text-amber-900'; ?>">TICKET #<?php echo $order['id']; ?></span>
                                        <span class="text-[9px] font-black px-1.5 py-0.5 rounded uppercase <?php echo $isPaid ? 'bg-emerald-100 text-emerald-900 border border-emerald-300' : 'bg-amber-100 text-amber-900 border border-amber-200'; ?>">
                                            <?php echo $isPaid ? 'Paid' : 'Hold'; ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-start mt-1.5">
                                        <span class="font-black text-xs text-slate-800 truncate capitalize"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    </div>
                                    <div class="mt-1.5 space-y-1 text-[11px] text-slate-600 max-h-[64px] overflow-y-auto">
                                        <?php if($items_res): ?>
                                            <?php while($item = $items_res->fetch_assoc()): ?>
                                                <div class="leading-tight">
                                                    <strong class="text-slate-800"><?php echo $item['qty']; ?>x <?php echo $item['prod_name']; ?></strong> 
                                                    <span class="text-[9px] text-indigo-700 font-semibold">(<?php echo $item['size']; ?>)</span>
                                                </div>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="pt-1.5 border-t border-dashed <?php echo $isPaid ? 'border-emerald-300' : 'border-amber-300'; ?> flex justify-between items-center">
                                    <span class="font-black text-xs text-slate-900">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                    <a href="index.php?action=complete_pending&id=<?php echo $order['id']; ?>" 
                                       onclick="return confirm('Serve Ticket #<?php echo $order['id']; ?>?')"
                                       class="bg-emerald-600 hover:bg-emerald-700 text-white font-black text-[10px] px-2.5 py-1.5 rounded-lg shadow transition uppercase tracking-wider">
                                        ✔ Serve
                                    </a>
                                </div>
                            </div>
                        <?php 
                            endwhile; 
                        endif; 
                        ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    <?php endif; ?>

    <!-- Configuration Modal Window -->
    <div id="customizer-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-2xl max-w-sm w-full shadow-2xl p-5 relative border border-slate-300">
            <h3 id="modal-product-name" class="text-md font-bold text-slate-800 border-b pb-1.5 mb-3">Drink Configuration</h3>
            
            <input type="hidden" id="modal-product-id">
            <input type="hidden" id="modal-base-price">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Select Drink Size</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="border p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-slate-50 transition border-[#051138] bg-slate-50" id="label-size-regular">
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="drink-size" value="Regular" checked onchange="updateModalSubtotal()" class="text-[#051138] focus:ring-[#051138]">
                                <span class="text-xs font-bold text-slate-700">Regular</span>
                            </div>
                            <span class="text-xs text-slate-400 font-medium">Base Price</span>
                        </label>
                        <label class="border p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-slate-50 transition border-slate-200" id="label-size-large">
                            <div class="flex items-center space-x-2">
                                <input type="radio" name="drink-size" value="Large" onchange="updateModalSubtotal()" class="text-[#051138] focus:ring-[#051138]">
                                <span class="text-xs font-bold text-slate-700">Large</span>
                            </div>
                            <span class="text-xs text-green-600 font-bold">+₱20.00</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-between items-center bg-slate-50 rounded-xl p-3 border border-dashed border-slate-300">
                    <span class="text-xs font-bold text-slate-600">Calculated Subtotal:</span>
                    <span id="modal-subtotal-display" class="font-black text-lg text-[#051138]">₱0.00</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 mt-5">
                <button onclick="closeCustomizer()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 px-4 rounded-xl text-xs transition">
                    Cancel
                </button>
                <button onclick="commitToCart()" class="bg-[#051138] hover:bg-indigo-900 text-white font-black py-2.5 px-4 rounded-xl text-xs transition uppercase tracking-wide shadow">
                    Add to Bill
                </button>
            </div>
        </div>
    </div>

    <script>
        let cart = [];

        function openCustomizer(product) {
            document.getElementById('modal-product-id').value = product.id;
            document.getElementById('modal-product-name').innerText = `Configure: ${product.name}`;
            document.getElementById('modal-base-price').value = product.base_price;
            
            document.querySelector('input[name="drink-size"][value="Regular"]').checked = true;
            updateModalSubtotal();
            
            document.getElementById('customizer-modal').classList.remove('hidden');
        }

        function closeCustomizer() {
            document.getElementById('customizer-modal').classList.add('hidden');
        }

        function updateModalSubtotal() {
            const basePrice = parseFloat(document.getElementById('modal-base-price').value);
            const isLarge = document.querySelector('input[name="drink-size"]:checked').value === 'Large';
            const subtotal = basePrice + (isLarge ? 20.00 : 0.00);
            
            document.getElementById('modal-subtotal-display').innerText = `₱${subtotal.toFixed(2)}`;
            
            const regLabel = document.getElementById('label-size-regular');
            const lrgLabel = document.getElementById('label-size-large');
            if (isLarge) {
                lrgLabel.className = "border p-2.5 rounded-xl flex items-center justify-between cursor-pointer bg-slate-50 border-[#051138]";
                regLabel.className = "border p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-slate-50 transition border-slate-200";
            } else {
                regLabel.className = "border p-2.5 rounded-xl flex items-center justify-between cursor-pointer bg-slate-50 border-[#051138]";
                lrgLabel.className = "border p-2.5 rounded-xl flex items-center justify-between cursor-pointer hover:bg-slate-50 transition border-slate-200";
            }
        }

        function commitToCart() {
            const id = document.getElementById('modal-product-id').value;
            const name = document.getElementById('modal-product-name').innerText.replace('Configure: ', '');
            const basePrice = parseFloat(document.getElementById('modal-base-price').value);
            const size = document.querySelector('input[name="drink-size"]:checked').value;
            const itemPrice = basePrice + (size === 'Large' ? 20.00 : 0.00);

            const existingItem = cart.find(i => i.id === id && i.size === size);

            if (existingItem) {
                existingItem.quantity += 1;
                existingItem.subtotal += itemPrice;
            } else {
                cart.push({
                    id: parseInt(id),
                    name: name,
                    size: size,
                    price: itemPrice,
                    quantity: 1,
                    subtotal: itemPrice
                });
            }

            closeCustomizer();
            renderCart();
        }

        function renderCart() {
            const container = document.getElementById('cart-container');
            if (!container) return; // Guard for non-cashier views

            if (cart.length === 0) {
                container.innerHTML = `
                    <div id="empty-cart-msg" class="text-center text-slate-400 my-auto pt-16">
                        <span class="text-3xl block mb-1">🛒</span> Cart Empty
                    </div>`;
                document.getElementById('summary-total').innerText = "₱0.00";
                calculateChange();
                return;
            }

            let html = "";
            let grandTotal = 0;

            cart.forEach((item, index) => {
                grandTotal += item.subtotal;
                html += `
                <div class="flex items-center justify-between bg-white/80 border border-slate-300 p-2.5 rounded-xl shadow-sm relative z-10">
                    <div class="flex-1 min-w-0 pr-2">
                        <h4 class="text-xs font-bold text-slate-800 truncate">${item.name}</h4>
                        <p class="text-[10px] text-slate-400 font-medium mt-0.5">${item.size} × ₱${item.price.toFixed(2)}</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="flex items-center bg-white border border-slate-200 rounded-lg">
                            <button onclick="updateQty(${index}, -1)" class="px-2 py-0.5 text-xs text-slate-500 hover:bg-slate-100 font-bold rounded-l-lg">-</button>
                            <span class="px-2 text-xs font-bold text-slate-700 min-w-[20px] text-center">${item.quantity}</span>
                            <button onclick="updateQty(${index}, 1)" class="px-2 py-0.5 text-xs text-slate-500 hover:bg-slate-100 font-bold rounded-r-lg">+</button>
                        </div>
                        <span class="text-xs font-bold text-[#051138] w-16 text-right">₱${item.subtotal.toFixed(2)}</span>
                    </div>
                </div>`;
            });

            container.innerHTML = html;
            document.getElementById('summary-total').innerText = `₱${grandTotal.toFixed(2)}`;
            calculateChange();
        }

        function updateQty(index, offset) {
            cart[index].quantity += offset;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            } else {
                cart[index].subtotal = cart[index].quantity * cart[index].price;
            }
            renderCart();
        }

        function clearCart() {
            cart = [];
            renderCart();
        }

        function calculateChange() {
            const sumDisplay = document.getElementById('summary-total');
            if (!sumDisplay) return;

            const totalText = sumDisplay.innerText.replace('₱', '');
            const total = parseFloat(totalText) || 0;
            const cash = parseFloat(document.getElementById('cash-input').value) || 0;
            const changeDisplay = document.getElementById('change-display');

            if (cash === 0 || cash < total) {
                changeDisplay.innerText = "₱0.00";
                changeDisplay.className = "font-black text-base text-slate-400";
            } else {
                const change = cash - total;
                changeDisplay.innerText = `₱${change.toFixed(2)}`;
                changeDisplay.className = "font-black text-base text-green-600";
            }
        }

        function submitOrder(isHold) {
            if (cart.length === 0) {
                alert("Please add items to your menu basket before attempting checkout processing.");
                return;
            }

            const totalText = document.getElementById('summary-total').innerText.replace('₱', '');
            const total = parseFloat(totalText);
            const customerName = document.getElementById('customer-name-input').value.trim();
            const cashInput = document.getElementById('cash-input').value;
            const cash = parseFloat(cashInput) || 0;

            if (!isHold && cash < total) {
                alert(`Insufficient payment balance. Total order amount is ₱${total.toFixed(2)}.`);
                return;
            }

            const payload = {
                cart: cart,
                total_amount: total,
                customer_name: customerName,
                cash_received: cash,
                is_pending: isHold
            };

            fetch('index.php?action=checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (isHold) {
                        alert(`Ticket Order #${data.order_id} placed on preparation HOLD successfully!`);
                    } else {
                        alert(`Cash Checkout completed for Order #${data.order_id}! Sent to pending preparation queue.`);
                    }
                    window.location.href = "index.php?view=cashier";
                } else {
                    alert(`System exception: ${data.message}`);
                }
            })
            .catch(err => {
                console.error(err);
                alert("An unexpected dynamic AJAX server runtime communication error occurred.");
            });
        }
    </script>
</body>
</html>