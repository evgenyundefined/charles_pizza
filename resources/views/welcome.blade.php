<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Пицца «Капричоза» от Prano-pizza — заказ онлайн</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="header">
    <div class="container header-inner">
        <div class="logo">Prano<span>pizza</span></div>
        <div class="header-contacts">
            <a href="tel:+79991234567" class="header-phone">+7 (999) 999‑999‑999</a>
            <div class="header-small">Звоните или оставьте заказ на сайте</div>
        </div>
    </div>
</header>

<section class="hero" id="top">
    <div class="container hero-inner">
        <div class="hero-text">
            <h1>Пицца «Капричоза» и другие капризы</h1>
            <p>Немного хаоса в начинке, немного магии внутри коробки — к каждой пицце мы приклеиваем предсказание, как в печенье удачи.</p>
            <a href="https://t.me/charles_pizza_bot"
               class="btn-primary"
               target="_blank"
               rel="noopener">
                Заказать пиццу в Telegram 🍕
            </a>
            <div class="hero-note">Доставка по городу с 11:00 до 23:00</div>
        </div>
        <div class="hero-photo">
            <div class="pizza-circle">🍕</div>
            <div class="hero-card">
                <div class="hero-card-title">Сегодняшнее предсказание</div>
                <div class="hero-card-text">«Путь длинный, шаг за шагом пройдёшь…»</div>
            </div>
        </div>
    </div>
</section>

<section class="menu" id="menu">
    <div class="container">
        <h2>Меню</h2>
        <div class="menu-grid">
            <article class="pizza-card">
                <h3>Капричоза</h3>
                <p>Ветчина, грибы, сыр, маслины — всё, что нашлось в холодильнике, но как будто так и задумывалось.</p>
                <div class="pizza-meta">от 550 ₽ • 30/35 см</div>
            </article>
            <article class="pizza-card">
                <h3>Маргарита</h3>
                <p>Классика: томатный соус, моцарелла и базилик. Когда хочется простого счастья.</p>
                <div class="pizza-meta">от 450 ₽</div>
            </article>
            <article class="pizza-card">
                <h3>4 сыра</h3>
                <p>Моцарелла, дорблю, пармезан и гауда. Для тех, кто выбирает сыр вместо слов.</p>
                <div class="pizza-meta">от 620 ₽</div>
            </article>
        </div>
    </div>
</section>

<section class="fortunes">
    <div class="container fortunes-inner">
        <div>
            <h2>Пицца + предсказание</h2>
            <p>В каждую коробку мы вклеиваем бумажку с маленьким прогнозом. Иногда смешным, иногда мудрым, но всегда вашим.</p>
            <p class="muted">Можно попросить «доброе», «жёсткое» или «рандом». Мы подберём настроение.</p>
        </div>
        <div class="fortunes-sample">
            <span>«Удача тихо, но верно подкрадывается»</span>
            <span>«Радость маленькая, счастьем станет»</span>
            <span>«Путь верный, шаг за шагом иди»</span>
        </div>
    </div>
</section>
<!--
<section class="order" id="order">
    <div class="container order-inner">
        <div class="order-text">
            <h2>Сделать заказ</h2>
            <p>Оставьте свои данные — мы ответим в Telegram или по телефону, уточним детали и время доставки.</p>
        </div>
        <form class="order-form" method="post" action="order.php">
            <div class="form-row">
                <label>Имя*</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-row">
                <label>Телефон*</label>
                <input type="tel" name="phone" required placeholder="+7 ___ ___‑__‑__">
            </div>
            <div class="form-row">
                <label>Адрес доставки*</label>
                <textarea name="address" rows="2" required></textarea>
            </div>
            <div class="form-row form-row-inline">
                <div>
                    <label>Пицца</label>
                    <select name="pizza">
                        <option value="Капричоза">Капричоза</option>
                        <option value="Маргарита">Маргарита</option>
                        <option value="4 сыра">4 сыра</option>
                        <option value="Другая (в комментарии)">Другая (в комментарии)</option>
                    </select>
                </div>
                <div>
                    <label>Размер</label>
                    <select name="size">
                        <option value="30 см">30 см</option>
                        <option value="35 см">35 см</option>
                    </select>
                </div>
                <div>
                    <label>Кол-во</label>
                    <input type="number" name="qty" min="1" value="1">
                </div>
            </div>
            <div class="form-row">
                <label>Комментарий</label>
                <textarea name="comment" rows="3" placeholder="Например: без лука, предсказание — максимально доброе :)"></textarea>
            </div>
            <button type="submit" class="btn-primary btn-full">Отправить заказ</button>
            <p class="form-note">Нажимая кнопку, вы соглашаетесь с обработкой персональных данных.</p>
        </form>
    </div>
</section>
-->
<footer class="footer">
    <div class="container footer-inner">
        <span>© 2025 Prana Pizza</span>
        <span>Телефон: +7 (999) 999‑999‑999</span>
    </div>
</footer>

</body>
</html>
