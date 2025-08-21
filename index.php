<?php session_start(); ?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navigation.php'; ?>
<?php include 'includes/header-section.php'; ?>

<!-- Equipment Section -->
<div class="equipment-section">
    <div class="container">
        <h2>Featured Equipment</h2>
        <p style="text-align: center; color: #666;">Popular farming equipment available for rent</p>

        <div class="equipment-row">
            <!-- Equipment 1 -->
            <div class="equipment-card">
                <div class="equipment-image tractor"></div>
                <div class="equipment-info">
                    <h3>Heavy Duty Tractor</h3>
                    <p><strong>Brand:</strong> Mahindra 575 DI</p>
                    <p><strong>Year:</strong> 2020</p>
                    <p><strong>Power:</strong> 75 HP</p>
                    <p><strong>Location:</strong> Surat, Gujarat</p>
                    <div class="price-box">
                        <span class="price">₹1,200/day</span>
                        <small>₹150/hour</small>
                    </div>
                    <a class="rent-btn"
   href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'equipments.php' : 'login.php' ?>">
   Rent Now
</a>

                </div>
            </div>

            <!-- Equipment 2 -->
            <div class="equipment-card">
                <div class="equipment-image harvester"></div>
                <div class="equipment-info">
                    <h3>Combine Harvester</h3>
                    <p><strong>Brand:</strong> John Deere W70</p>
                    <p><strong>Year:</strong> 2019</p>
                    <p><strong>Type:</strong> Self-Propelled</p>
                    <p><strong>Location:</strong> Rajkot, Gujarat</p>
                    <div class="price-box">
                        <span class="price">₹2,500/day</span>
                        <small>₹300/hour</small>
                    </div>
                    <button class="rent-btn">Rent Now</button>
                </div>
            </div>

            <!-- Equipment 3 -->
            <div class="equipment-card">
                <div class="equipment-image tiller"></div>
                <div class="equipment-info">
                    <h3>Rotary Tiller</h3>
                    <p><strong>Brand:</strong> Fieldking</p>
                    <p><strong>Year:</strong> 2021</p>
                    <p><strong>Width:</strong> 8 Feet</p>
                    <p><strong>Location:</strong> Anand, Gujarat</p>
                    <div class="price-box">
                        <span class="price">₹800/day</span>
                        <small>₹100/hour</small>
                    </div>
                    <button class="rent-btn">Rent Now</button>
                </div>
            </div>

            <!-- Equipment 4 -->
            <div class="equipment-card">
                <div class="equipment-image tractor2"></div>
                <div class="equipment-info">
                    <h3>Heavy Duty Tractor</h3>
                    <p><strong>Brand:</strong> Mahindra 575 DI</p>
                    <p><strong>Year:</strong> 2020</p>
                    <p><strong>Power:</strong> 75 HP</p>
                    <p><strong>Location:</strong> Surat, Gujarat</p>
                    <div class="price-box">
                        <span class="price">₹1,200/day</span>
                        <small>₹150/hour</small>
                    </div>
                    <button class="rent-btn">Rent Now</button>
                </div>
            </div>
        </div>


        <div style="text-align: center; margin-top: 30px;">
            <a href="equipments.php" class="view-all-btn">View All Equipment</a>
        </div>
    </div>
</div>

<!-- Products Section -->
<div class="products-section">
    <div class="container">
        <h2>Farm Supplies & Products</h2>
        <p style="text-align: center; color: #666;">Quality agricultural products and supplies</p>

        <div class="products-row">
            <!-- Product 1 -->
            <div class="product-card">
                <div class="product-image seeds"></div>                
                <div class="product-info">
                    <h3>Hybrid Wheat Seeds</h3>
                    <p><strong>Variety:</strong> HD-2967</p>
                    <p><strong>Quality:</strong> Premium Grade</p>
                    <p><strong>Quantity:</strong> 50 Kg Bag</p>
                    <p><strong>Seller:</strong> Anand Seeds Co.</p>
                    <div class="price-box">
                        <span class="price">₹2,500/bag</span>
                        <small>₹50/kg</small>
                    </div>
                    <a class="buy-btn"href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'products.php' : 'login.php' ?>">Buy Now</a>
                </div>
            </div>

            <!-- Product 2 -->
            <div class="product-card">
                <div style="width: 100%; height: 180px; background: #ddd; display: flex; align-items: center; justify-content: center; color: #666;">Product Image</div>
                <div class="product-info">
                    <h3>Organic Fertilizer</h3>
                    <p><strong>Type:</strong> NPK 19:19:19</p>
                    <p><strong>Brand:</strong> FarmGrow</p>
                    <p><strong>Quantity:</strong> 25 Kg Bag</p>
                    <p><strong>Seller:</strong> Gujarat Agro</p>
                    <div class="price-box">
                        <span class="price">₹1,200/bag</span>
                        <small>₹48/kg</small>
                    </div>
                    <button class="buy-btn">Buy Now</button>
                </div>
            </div>

            <!-- Product 3 -->
            <div class="product-card">
                <div style="width: 100%; height: 180px; background: #ddd; display: flex; align-items: center; justify-content: center; color: #666;">Product Image</div>
                <div class="product-info">
                    <h3>Plant Protection</h3>
                    <p><strong>Type:</strong> Insecticide</p>
                    <p><strong>Brand:</strong> CropSafe</p>
                    <p><strong>Quantity:</strong> 1 Liter</p>
                    <p><strong>Seller:</strong> AgriCare Ltd</p>
                    <div class="price-box">
                        <span class="price">₹450/liter</span>
                        <small>Concentrated</small>
                    </div>
                    <button class="buy-btn">Buy Now</button>
                </div>
            </div>

            <!-- Product 4 -->
            <div class="product-card">
                <div style="width: 100%; height: 180px; background: #ddd; display: flex; align-items: center; justify-content: center; color: #666;">Product Image</div>
                <div class="product-info">
                    <h3>Farming Tools Set</h3>
                    <p><strong>Items:</strong> 5 Piece Set</p>
                    <p><strong>Material:</strong> Steel</p>
                    <p><strong>Brand:</strong> FarmPro</p>
                    <p><strong>Seller:</strong> Tool Mart</p>
                    <div class="price-box">
                        <span class="price">₹2,800/set</span>
                        <small>Complete Kit</small>
                    </div>
                    <button class="buy-btn">Buy Now</button>
                </div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="products.php" class="view-all-btn">View All Products</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>