<?php
// Bật hiển thị tất cả lỗi
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) {
    echo "<div class='alert alert-warning'>Vui lòng đăng nhập trước!</div>";
    header("Location: index.php");
    exit();
}

include 'config.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$cart_msg = '';
$cart_id = null;

// Lấy danh sách sản phẩm
$products = [];
if (isset($connect) && $connect) {
    $sql = "SELECT id, name FROM products";
    $result = mysqli_query($connect, $sql);
    if ($result) {
        $products = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
    } else {
        $cart_msg = "<div class='alert alert-danger'>Lỗi lấy danh sách sản phẩm: " . mysqli_error($connect) . "</div>";
    }
}

if (isset($connect) && $connect) {
    $sql = "SELECT id FROM carts WHERE user_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt === false) {
        $cart_msg = "<div class='alert alert-danger'>Lỗi chuẩn bị truy vấn carts: " . mysqli_error($connect) . "</div>";
    } else {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (!mysqli_stmt_execute($stmt)) {
            $cart_msg = "<div class='alert alert-danger'>Lỗi thực thi truy vấn carts: " . mysqli_error($connect) . "</div>";
        } else {
            $result = mysqli_stmt_get_result($stmt);
            $cart = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($cart) {
                $cart_id = $cart['id'];
                echo "<div class='alert alert-info'>";
            } else {
                $sql = "INSERT INTO carts (user_id) VALUES (?)";
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    $cart_msg = "<div class='alert alert-danger'>Lỗi chuẩn bị tạo carts: " . mysqli_error($connect) . "</div>";
                } else {
                    mysqli_stmt_bind_param($stmt, "i", $user_id);
                    if (!mysqli_stmt_execute($stmt)) {
                        $cart_msg = "<div class='alert alert-danger'>Lỗi tạo giỏ hàng: " . mysqli_error($connect) . "</div>";
                    } else {
                        $cart_id = mysqli_insert_id($connect);
                        echo "<div class='alert alert-success'>Giỏ hàng mới ID $cart_id đã được tạo.</div>";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

// Add to cart
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_cart']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token'] && isset($connect) && $connect && $cart_id) {
    $product_id = intval($_POST['product_id']);
    $sql = "SELECT id FROM cart_items WHERE cart_id = ? AND product_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $cart_id, $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {
            $sql = "UPDATE cart_items SET quantity = quantity + 1 WHERE cart_id = ? AND product_id = ?";
            $stmt = mysqli_prepare($connect, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $cart_id, $product_id);
        } else {
            $sql = "INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, 1)";
            $stmt = mysqli_prepare($connect, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $cart_id, $product_id);
        }
        if ($stmt && mysqli_stmt_execute($stmt)) {
            $cart_msg = "<div class='alert alert-success'>Thêm vào giỏ hàng thành công!</div>";
        } else {
            $cart_msg = "<div class='alert alert-danger'>Lỗi thêm sản phẩm: " . mysqli_error($connect) . "</div>";
        }
        mysqli_stmt_close($stmt);
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Update cart
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_cart']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token'] && isset($connect) && $connect && $cart_id) {
    $quantities = $_POST['quantity'] ?? [];
    foreach ($quantities as $item_id => $quantity) {
        $quantity = intval($quantity);
        $item_id = intval($item_id);
        $sql = $quantity <= 0 ? "DELETE FROM cart_items WHERE id = ? AND cart_id = ?" : "UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?";
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt) {
            if ($quantity <= 0) {
                mysqli_stmt_bind_param($stmt, "ii", $item_id, $cart_id);
            } else {
                mysqli_stmt_bind_param($stmt, "iii", $quantity, $item_id, $cart_id);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    $cart_msg = "<div class='alert alert-success'>Cập nhật giỏ hàng thành công!</div>";
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Remove item
if (isset($_GET['remove_item']) && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token'] && isset($connect) && $connect && $cart_id) {
    $item_id = intval($_GET['remove_item']);
    $sql = "DELETE FROM cart_items WHERE id = ? AND cart_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $item_id, $cart_id);
        if (mysqli_stmt_execute($stmt)) {
            $cart_msg = "<div class='alert alert-success'>Xóa sản phẩm thành công!</div>";
        } else {
            $cart_msg = "<div class='alert alert-danger'>Lỗi xóa sản phẩm: " . mysqli_error($connect) . "</div>";
        }
        mysqli_stmt_close($stmt);
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Place order
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token'] && isset($connect) && $connect && $cart_id) {
    $shipping_address = mysqli_real_escape_string($connect, $_POST['shipping_address']);
    if (empty($shipping_address)) {
        $cart_msg = "<div class='alert alert-danger'>Vui lòng nhập địa chỉ giao hàng!</div>";
    } else {
        $sql = "SELECT ci.id, ci.quantity, p.id as product_id, p.price, p.name 
                FROM cart_items ci 
                JOIN products p ON ci.product_id = p.id 
                WHERE ci.cart_id = ?";
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $cart_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $items = mysqli_fetch_all($result, MYSQLI_ASSOC);
            mysqli_stmt_close($stmt);

            if (empty($items)) {
                $cart_msg = "<div class='alert alert-danger'>Giỏ hàng trống!</div>";
            } else {
                $total_amount = 0;
                foreach ($items as $item) {
                    $total_amount += $item['quantity'] * $item['price'];
                }

                $sql = "INSERT INTO orders (user_id, total_amount, status, shipping_address) VALUES (?, ?, 'pending', ?)";
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "ids", $user_id, $total_amount, $shipping_address);
                    if (mysqli_stmt_execute($stmt)) {
                        $order_id = mysqli_insert_id($connect);
                        mysqli_stmt_close($stmt);

                        foreach ($items as $item) {
                            $sql = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                            $stmt = mysqli_prepare($connect, $sql);
                            mysqli_stmt_bind_param($stmt, "iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }

                        $sql = "DELETE FROM cart_items WHERE cart_id = ?";
                        $stmt = mysqli_prepare($connect, $sql);
                        mysqli_stmt_bind_param($stmt, "i", $cart_id);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        $cart_msg = "<div class='alert alert-success'>Đặt hàng thành công! Mã đơn: #$order_id</div>";
                    } else {
                        $cart_msg = "<div class='alert alert-danger'>Lỗi tạo đơn hàng: " . mysqli_error($connect) . "</div>";
                    }
                }
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get cart items
$cart_items = [];
if (isset($connect) && $connect && $cart_id) {
    $sql = "SELECT ci.id, ci.quantity, p.id as product_id, p.name, p.price, p.image 
            FROM cart_items ci 
            JOIN products p ON ci.product_id = p.id 
            WHERE ci.cart_id = ?";
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt === false) {
        $cart_msg = "<div class='alert alert-danger'>Lỗi chuẩn bị truy vấn cart_items: " . mysqli_error($connect) . "</div>";
    } else {
        mysqli_stmt_bind_param($stmt, "i", $cart_id);
        if (!mysqli_stmt_execute($stmt)) {
            $cart_msg = "<div class='alert alert-danger'>Lỗi thực thi truy vấn cart_items: " . mysqli_error($connect) . "</div>";
        } else {
            $result = mysqli_stmt_get_result($stmt);
            $cart_items = mysqli_fetch_all($result, MYSQLI_ASSOC);
        }
        mysqli_stmt_close($stmt);
    }
}

$total_amount = 0;
foreach ($cart_items as $item) {
    $total_amount += $item['quantity'] * $item['price'];
}

$sql = "SELECT full_name FROM users WHERE id = ?";
$stmt = mysqli_prepare($connect, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
$_SESSION['full_name'] = $user['full_name'] ?? 'Người dùng';
mysqli_stmt_close($stmt);

if (isset($connect)) mysqli_close($connect); // Đóng kết nối cuối cùng
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giỏ Hàng - BTEC Sweet Shop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4A90E2;
            --secondary-color: #4B5563;
            --accent-color: #FEF3C7;
            --background-color: #FFF7ED;
            --hover-color: #3B82F6;
        }
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: url('https://images.unsplash.com/photo-1600585154340-be6161a56a0c') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }
        .wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: rgba(255, 247, 237, 0.95);
        }
        .header {
            background: linear-gradient(90deg, #ffffff, var(--accent-color));
            padding: 12px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .logo img {
            height: 50px;
            margin-left: 20px;
            transition: transform 0.3s ease;
        }
        .logo img:hover {
            transform: scale(1.1);
        }
        .form-search {
            max-width: 450px;
            flex-grow: 1;
            margin: 0 20px;
        }
        .form-search input[type="text"] {
            border-radius: 20px 0 0 20px;
            padding: 10px 15px;
            font-size: 14px;
            border: 1px solid #d1d5db;
            transition: border-color 0.3s ease;
        }
        .form-search input[type="text"]:focus {
            border-color: var(--primary-color);
            outline: none;
        }
        .form-search button {
            border-radius: 0 20px 20px 0;
            padding: 10px 15px;
            background-color: var(--primary-color);
            border: none;
            color: white;
            transition: background-color 0.3s ease;
        }
        .form-search button:hover {
            background-color: var(--hover-color);
        }
        .icon-cart img, .icon-user img {
            height: 30px;
            width: 30px;
            margin: 0 12px;
            transition: transform 0.3s ease;
        }
        .icon-cart img:hover, .icon-user img:hover {
            transform: scale(1.2);
        }
        .user-name {
            font-size: 14px;
            font-weight: 500;
            color: var(--secondary-color);
            margin-left: 8px;
        }
        .user-dropdown .dropdown-toggle::after {
            display: none;
        }
        .navbar {
            background-color: var(--primary-color);
            padding: 8px 0;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            z-index: 999;
        }
        .navbar-nav .nav-link {
            color: #fff !important;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 18px;
            transition: background-color 0.3s ease, color 0.3s ease;
            border-radius: 6px;
            margin: 0 5px;
        }
        .navbar-nav .nav-link:hover, .navbar-nav .nav-link.active {
            background-color: var(--hover-color);
            color: #fff !important;
        }
        .dropdown-menu {
            z-index: 1001;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background-color: #fff;
        }
        .dropdown-menu .dropdown-item {
            font-size: 14px;
            padding: 8px 15px;
            transition: background-color 0.3s ease;
        }
        .dropdown-menu .dropdown-item:hover {
            background-color: var(--accent-color);
            color: var(--primary-color);
        }
        .content {
            flex: 1;
            padding: 25px 0;
            display: flex;
            justify-content: center; /* Căn giữa nội dung theo chiều ngang */
        }
        .cart-section {
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 800px; /* Giới hạn chiều rộng tối đa */
            width: 100%; /* Đảm bảo sử dụng toàn bộ chiều rộng có sẵn trong giới hạn */
            margin: 0 auto; /* Căn giữa theo chiều ngang */
        }
        .cart-table img {
            max-width: 50px;
            height: auto;
            border-radius: 4px;
        }
        .text-end {
            text-align: right;
        }
        .footer {
            background: linear-gradient(90deg, var(--primary-color), var(--hover-color));
            color: #fff;
            padding: 50px 0;
        }
        .footer a {
            color: #fff;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .footer a:hover {
            color: var(--accent-color);
        }
        .footer .social-icons a {
            font-size: 22px;
            margin: 0 12px;
        }
        .newsletter-form input[type="email"] {
            border-radius: 20px 0 0 20px;
            padding: 10px 15px;
            font-size: 14px;
            border: none;
        }
        .newsletter-form button {
            border-radius: 0 20px 20px 0;
            padding: 10px 15px;
            background-color: var(--hover-color);
            border: none;
            color: #fff;
            transition: background-color 0.3s ease;
        }
        .newsletter-form button:hover {
            background-color: #2563EB;
        }
        .animate__fadeIn {
            animation: fadeIn 0.6s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <header class="header">
            <div class="container-fluid d-flex align-items-center justify-content-between">
                <div class="logo">
                    <a href="banbanh.php"><img src="https://cdn.haitrieu.com/wp-content/uploads/2023/02/Logo-Truong-cao-dang-Quoc-te-BTEC-FPT.png" alt="BTEC Sweet Shop"></a>
                </div>
                <form class="form-search d-flex" action="product.php" method="GET" role="search">
                    <input type="text" name="search" placeholder="Tìm kiếm bánh kẹo..." class="form-control" aria-label="Tìm kiếm sản phẩm">
                    <button type="submit" class="btn" aria-label="Tìm kiếm"><i class="fas fa-search"></i></button>
                </form>
                <div class="icon-cart">
                    <a href="cart.php" aria-label="Giỏ hàng"><img src="https://cdn-icons-png.flaticon.com/512/3144/3144456.png" alt="Cart"></a>
                </div>
                <div class="icon-user dropdown d-flex align-items-center">
                    <a href="account.php" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Tài khoản" aria-haspopup="true">
                        <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="account.php">Hồ Sơ</a></li>
                        <li><a class="dropdown-item" href="account.php#orders">Đơn Hàng</a></li>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="admin.php">Quản Trị</a></li>
                            <!-- <li><a class="dropdown-item" href="banbanh.php">Thêm Sản Phẩm</a></li> -->
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="login_register.php">Đăng Xuất</a></li>
                    </ul>
                </div>
            </div>
        </header>
        <nav class="navbar navbar-expand-md sticky-top">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item"><a class="nav-link" href="product.php" aria-current="page">Tất Cả Sản Phẩm</a></li>
                        <li class="nav-item"><a class="nav-link" href="account.php">Tài Khoản</a></li>
                        <li class="nav-item"><a class="nav-link active" href="cart.php">Giỏ Hàng</a></li>
                        <li class="nav-item"><a class="nav-link" href="contact.php">Liên Hệ</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <div class="content container-fluid animate__fadeIn">
            <div class="cart-section">
                <h2>Giỏ Hàng của <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Người dùng'); ?></h2>
                <?php echo $cart_msg; ?>
                <?php if (empty($cart_items)): ?>
                    <p class="text-center">Giỏ hàng của bạn đang trống.</p>
                <?php else: ?>
                    <form action="" method="POST">
                        <input type="hidden" name="update_cart" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="table-responsive">
                            <table class="table table-bordered cart-table">
                                <thead>
                                    <tr>
                                        <th>Hình Ảnh</th>
                                        <th>Sản Phẩm</th>
                                        <th>Giá</th>
                                        <th>Số Lượng</th>
                                        <th>Tổng</th>
                                        <th>Xóa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart_items as $item): ?>
                                        <tr>
                                            <td><img src="<?php echo htmlspecialchars($item['image'] ?? 'images/placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>"></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo number_format($item['price'], 0, ',', '.'); ?>đ</td>
                                            <td><input type="number" name="quantity[<?php echo $item['id']; ?>]" value="<?php echo $item['quantity']; ?>" min="1" class="form-control w-50 d-inline"></td>
                                            <td><?php echo number_format($item['quantity'] * $item['price'], 0, ',', '.'); ?>đ</td>
                                            <td><a href="cart.php?remove_item=<?php echo $item['id']; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Bạn có chắc chắn muốn xóa?');">Xóa</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-primary">Cập Nhật Giỏ Hàng</button>
                        </div>
                    </form>
                    <div class="mt-4">
                        <h4>Tổng Cộng: <?php echo number_format($total_amount, 0, ',', '.'); ?>đ</h4>
                        <form action="" method="POST">
                            <input type="hidden" name="place_order" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="mb-3">
                                <label for="shipping_address" class="form-label">Địa Chỉ Giao Hàng</label>
                                <textarea class="form-control" id="shipping_address" name="shipping_address" rows="3" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-success">Đặt Hàng</button>
                        </form>
                    </div>
                <?php endif; ?>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="add_to_cart" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="input-group mb-3" style="max-width: 300px;">
                        <select name="product_id" class="form-control" required>
                            <option value="">Chọn sản phẩm</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo htmlspecialchars($product['id']); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Thêm Sản Phẩm</button>
                    </div>
                </form>
            </div>
        </div>
        <footer class="footer">
            <div class="container">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <h5>Giới Thiệu</h5>
                        <p>BTEC Sweet Shop mang đến những loại bánh kẹo ngon, chất lượng cao, lan tỏa niềm vui ngọt ngào cho mọi nhà.</p>
                    </div>
                    <div class="col-md-4 mb-4">
                        <h5>Liên Hệ</h5>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-map-marker-alt me-2"></i>406 Xuân Phương</li>
                            <li><i class="fas fa-phone me-2"></i>0899133869</li>
                            <li><i class="fas fa-envelope me-2"></i>hoa2282005hhh@gmail.com</li>
                        </ul>
                    </div>
                    <div class="col-md-4 mb-4">
                        <h5>Đăng Ký Bản Tin</h5>
                        <form class="newsletter-form d-flex">
                            <input type="email" placeholder="Nhập email của bạn..." class="form-control" aria-label="Email đăng ký bản tin" required>
                            <button type="submit" class="btn">Đăng Ký</button>
                        </form>
                        <h5 class="mt-4">Theo Dõi Chúng Tôi</h5>
                        <div class="social-icons">
                            <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                            <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <p>© 2025 BTEC Sweet Shop. All Rights Reserved.</p>
                </div>
            </div>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>