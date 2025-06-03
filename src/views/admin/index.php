<!-- файл src/views/admin/index.php -->
<div class="container mt-5">
    <h1>Панель администратора</h1>
    <p>Добро пожаловать, <?= htmlspecialchars($user['username'], ENT_QUOTES) ?>!</p>
    <ul>
        <li><a href="/shop">Просмотр каталога</a></li>
        <li><a href="/cart">Корзина</a></li>
        <li><a href="/specification/create" id="createSpecLink">Создать спецификацию</a></li>
        <li><a href="/admin/diagnost">🔍 Диагностика системы</a></li>
        <li><a href="/admin/documentation" id="documentationLink">📚 Документация</a></li>
        <!-- сюда можно добавить другие ссылки на админ-функции -->
    </ul>
</div>