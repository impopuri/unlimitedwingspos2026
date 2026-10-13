<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ETIVAC WINGS POS · Login</title>
    <style>
        :root{font-family:Inter,"Segoe UI",Arial,sans-serif;color:#272727;background:#f4f5f7}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px}.login{width:min(430px,100%);background:#fff;border-radius:20px;padding:38px;box-shadow:0 18px 60px #18233314}.brand{display:flex;align-items:center;gap:14px;margin-bottom:30px}.brand img{width:58px;height:58px;object-fit:contain}.brand strong{display:block;font-size:17px;letter-spacing:.02em}.brand span{font-size:13px;color:#747b85}.eyebrow{color:#d92731;font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}h1{font-size:28px;margin:9px 0 8px}.hint{font-size:14px;color:#737984;margin:0 0 24px}label{display:block;font-size:13px;font-weight:700;margin:16px 0 7px}input{width:100%;height:46px;border:1px solid #dce0e6;border-radius:9px;padding:0 12px;font-size:15px}input:focus{outline:2px solid #d9273133;border-color:#d92731}button{width:100%;height:48px;margin-top:23px;border:0;border-radius:9px;background:#d92731;color:white;font-weight:800;font-size:15px;cursor:pointer}.error{background:#fff0f0;border:1px solid #f4c8ca;border-radius:8px;color:#9b1c24;padding:10px 12px;font-size:13px;margin-top:16px}.demo{margin-top:22px;border-top:1px solid #edf0f3;padding-top:17px;color:#737984;font-size:12px;line-height:1.7}.demo strong{color:#343a40}
    </style>
</head>
<body>
    <main class="login">
        <div class="brand"><img src="{{ asset('images/EWLOGO.png') }}" alt="ETIVAC WINGS logo"><div><strong>ETIVAC WINGS</strong><span>Point of Sale</span></div></div>
        <div class="eyebrow">Restaurant operations</div>
        <h1>Welcome back</h1>
        <p class="hint">Sign in to open the restaurant workspace.</p>
        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('pos.login') }}">
            @csrf
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" required autofocus value="{{ old('username') }}">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">SIGN IN</button>
        </form>
        <div class="demo"><strong>Local development accounts</strong><br>admin · cashier · kitchen<br>Password: password (change before production)</div>
    </main>
</body>
</html>
