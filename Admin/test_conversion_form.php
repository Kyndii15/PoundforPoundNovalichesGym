<!DOCTYPE html>
<html>
<head>
    <title>Test Walk-in Conversion</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 300px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .debug { background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 4px; margin-top: 20px; }
    </style>
</head>
<body>
    <h2>Test Walk-in Conversion Form</h2>
    
    <form method="POST" action="convert_walkin_to_member_debug.php">
        <input type="hidden" name="action" value="convert">
        
        <div class="form-group">
            <label for="walkin_id">Walk-in ID:</label>
            <input type="number" id="walkin_id" name="walkin_id" value="1" required>
        </div>
        
        <div class="form-group">
            <label for="email">Email Address:</label>
            <input type="email" id="email" name="email" value="test@example.com" required autocomplete="off">
        </div>
        
        <div class="form-group">
            <label for="phone">Phone Number:</label>
            <input type="tel" id="phone" name="phone" value="+63 912 345 6789">
        </div>
        
        <div class="form-group">
            <label for="address">Address:</label>
            <input type="text" id="address" name="address" value="123 Test Street, Test City" autocomplete="off">
        </div>
        
        <div class="form-group">
            <label for="membership_plan">Membership Plan:</label>
            <select id="membership_plan" name="membership_plan" required>
                <option value="">Select a membership plan</option>
                <option value="Package 1: Boxing (1 Month)">Package 1: Boxing (1 Month) - ₱2,500</option>
                <option value="Package 2: Circuit Training (1 Month)">Package 2: Circuit Training (1 Month) - ₱1,700</option>
                <option value="Package 3: Muay Thai (1 Month)">Package 3: Muay Thai (1 Month) - ₱3,000</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="payment_method">Payment Method:</label>
            <select id="payment_method" name="payment_method" required>
                <option value="">Select payment method</option>
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
            </select>
        </div>
        
        <button type="submit">Test Conversion</button>
    </form>
    
    <div class="debug">
        <h3>Instructions:</h3>
        <ol>
            <li>First, run <a href="test_database_structure.php">test_database_structure.php</a> to check if all tables exist</li>
            <li>Make sure you have at least one walk-in record in the database with ID 1</li>
            <li>Fill in the form above and click "Test Conversion"</li>
            <li>Check the debug output to see what's happening</li>
        </ol>
    </div>
</body>
</html>

