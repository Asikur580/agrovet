<!DOCTYPE html>
<html>
<head>
    <title>Order Created</title>
</head>
<body>
    <h2>Order Created Successfully!</h2>
    <p>Customer ID: {{ $order->customer->customer_name }}</p>
    <p>Order Date: {{ $order->order_date }}</p>
    <p>Order Type: {{ ucfirst($order->order_type) }}</p>
    <p>Discount: {{ $order->discount }} Taka</p>

    <h3>Products:</h3>
    <ul>
        @foreach($order->orderProducts as $product)
            <li>
                {{ $product->product->name }} - Qty: {{ $product->quantity }} - Price: {{ $product->unit_price }} Taka
            </li>
        @endforeach
    </ul>
</body>
</html>
