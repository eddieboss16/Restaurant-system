<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Restaurant System</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-sm bg-white rounded-xl shadow p-8">
        <h1 class="text-2xl font-semibold text-slate-800 mb-1">Restaurant System</h1>
        <p class="text-sm text-slate-500 mb-6">Sign in to continue.</p>

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/login" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1" for="email">Email</label>
                <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                    class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1" for="password">Password</label>
                <input id="password" name="password" type="password" required
                    class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
            </div>
            <button type="submit"
                class="w-full rounded bg-slate-800 text-white py-2 text-sm font-medium hover:bg-slate-700">
                Sign in
            </button>
        </form>
    </div>
</body>
</html>
