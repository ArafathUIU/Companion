<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Book Counselling - CompanionX</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { inter: ['Inter', 'sans-serif'] },
          colors: {
            primary: '#3B82F6',
            accent: '#10B981',
            dark: '#111827',
            light: '#F9FAFB',
          }
        }
      }
    };
  </script>
</head>
<body class="font-inter bg-light text-dark">
  <div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="w-64 bg-white shadow-lg fixed h-full p-6">
      <h1 class="text-2xl font-bold text-primary mb-10">CompanionX</h1>
      <nav class="space-y-4">
        <a href="dashboard.php" class="block px-4 py-2 rounded hover:bg-gray-100">Dashboard</a>
        <a href="book_counselling.php" class="block px-4 py-2 bg-primary text-white rounded">Book Counselling</a>
        <a href="logout.php" class="block px-4 py-2 rounded hover:bg-gray-100">Logout</a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="ml-64 w-full p-8">
      <header class="mb-8 flex justify-between items-center">
        <h2 class="text-3xl font-semibold">Book a Counselling Session</h2>
      </header>

      <!-- Search Filters -->
      <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-10 bg-white p-6 rounded shadow">
        <input type="text" name="specialization" placeholder="Specialization (e.g., Anxiety)" class="p-3 border rounded shadow-sm">
        <input type="text" name="day" placeholder="Available Day (e.g., Monday)" class="p-3 border rounded shadow-sm">
        <input type="number" name="max_charge" placeholder="Max Charge (৳)" class="p-3 border rounded shadow-sm">
        <button type="submit" class="bg-primary text-white px-4 py-2 rounded hover:bg-blue-700">Search</button>
      </form>

      <!-- Top 5 Recommendations -->
      <h3 class="text-xl font-semibold text-accent mb-4">Top 5 Recommended Consultants</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <?php foreach ($top5 as $consultant): ?>
        <div class="bg-white rounded-lg shadow hover:shadow-xl transition p-5">
          <div class="flex items-center mb-4">
            <img src="uploads/<?= htmlspecialchars($consultant['profile_picture'] ?? 'default.jpg') ?>" class="w-14 h-14 rounded-full object-cover mr-4" alt="Avatar">
            <div>
              <h4 class="text-lg font-bold text-primary"><?= htmlspecialchars($consultant['first_name'] . ' ' . $consultant['last_name']) ?></h4>
              <p class="text-sm text-gray-500"><?= htmlspecialchars($consultant['specialization']) ?></p>
            </div>
          </div>
          <p class="text-gray-700 mb-3">Charge: <span class="text-green-600 font-semibold">৳<?= htmlspecialchars($consultant['session_charge']) ?></span></p>
          <a href="book_session.php?consultant_id=<?= $consultant['id'] ?>" class="bg-accent text-white px-4 py-2 rounded hover:bg-emerald-600">Book Now</a>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- All Consultants -->
      <h3 class="text-xl font-semibold mb-4">Other Available Consultants</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($consultants as $consultant): ?>
        <div class="bg-white rounded-lg shadow hover:shadow-lg transition p-5">
          <div class="flex items-center mb-4">
            <img src="uploads/<?= htmlspecialchars($consultant['profile_picture'] ?? 'default.jpg') ?>" class="w-14 h-14 rounded-full object-cover mr-4" alt="Avatar">
            <div>
              <h4 class="text-lg font-bold text-primary"><?= htmlspecialchars($consultant['first_name'] . ' ' . $consultant['last_name']) ?></h4>
              <p class="text-sm text-gray-500"><?= htmlspecialchars($consultant['specialization']) ?></p>
            </div>
          </div>
          <p class="text-gray-700 mb-3">Charge: <span class="text-green-600 font-semibold">৳<?= htmlspecialchars($consultant['session_charge']) ?></span></p>
          <a href="book_session.php?consultant_id=<?= $consultant['id'] ?>" class="bg-primary text-white px-4 py-2 rounded hover:bg-blue-700">Book Now</a>
        </div>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</body>
</html>
