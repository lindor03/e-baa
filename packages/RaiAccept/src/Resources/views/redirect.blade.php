<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Redirecting to payment...</title>
</head>
<body>
    <p>Redirecting to secure payment page...</p>

    <form id="raiaccept_payment_form" method="POST" action="{{ $gateway }}">
        @foreach ($data as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <noscript>
            <button type="submit">Continue to payment</button>
        </noscript>
    </form>

    <script>
        document.getElementById('raiaccept_payment_form').submit();
    </script>
</body>
</html>
