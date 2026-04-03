<nav class="navbar navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-tools"></i> JDB Parts - Staff Panel
        </a>
        <div class="d-flex">
            <span class="navbar-text me-3 text-white">
                Hello, <?= htmlspecialchars($_SESSION['user_name']) ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid mt-5 pt-4">
    <div class="row">
        <div class="col-md-3 col-lg-2">
            <div class="list-group mt-4">
                <a href="dashboard.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="orders.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-shopping-cart"></i> Orders
                </a>
                <a href="products.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-box"></i> Products
                </a>
                <a href="customers.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-users"></i> Customers
                </a>
            </div>
        </div>
        <div class="col-md-9 col-lg-10">
            <div class="card shadow">
                <div class="card-body">