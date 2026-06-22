<div align="center">

# 📺 NiraServer Panel

### Легковесная PHP-панель для управления IPTV-вещанием

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Code Size](https://img.shields.io/github/languages/code-size/threadresercher28/niraserver-panel?color=purple)](https://github.com/threadresercher28/niraserver-panel)
[![Last Commit](https://img.shields.io/github/last-commit/threadresercher28/niraserver-panel?color=red)](https://github.com/threadresercher28/niraserver-panel/commits/main)

[![IPTV Ready](https://img.shields.io/badge/IPTV-Ready-brightgreen)](#-использование)
[![M3U8 Support](https://img.shields.io/badge/M3U8-Support-blueviolet)](#-генератор-плейлистов-genaphp)
[![HLS Streaming](https://img.shields.io/badge/HLS-Streaming-yellow)](#-технологии)
[![Secure Proxy](https://img.shields.io/badge/Proxy-Secured-red)](#-безопасный-прокси-сервер-niraphp)

[![GitHub stars](https://img.shields.io/github/stars/threadresercher28/niraserver-panel?style=social)](https://github.com/threadresercher28/niraserver-panel/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/threadresercher28/niraserver-panel?style=social)](https://github.com/threadresercher28/niraserver-panel/network/members)
[![GitHub issues](https://img.shields.io/github/issues/threadresercher28/niraserver-panel?style=social)](https://github.com/threadresercher28/niraserver-panel/issues)

</div>

---

## 📋 Содержание

- [О проекте](#-о-проекте)
- [Возможности](#-ключевые-возможности)
- [Технологии](#-технологии)
- [Структура проекта](#-структура-проекта)
- [Требования](#-требования)
- [Структура БД](#-структура-базы-данных)
- [Установка](#-установка)
- [Использование](#-использование)
- [Безопасность](#-безопасность)
- [Лицензия](#-лицензия)

---

## 🎯 О проекте

**NiraServer Panel** — это легковесная PHP-панель для управления IPTV-вещанием. Проект реализует полный цикл работы со стриминговым контентом: от администрирования базы каналов и пользователей до генерации клиентских M3U-плейлистов и безопасного проксирования видеопотоков с защитой от SSRF-атак.

---

## 🚀 Ключевые возможности

### 🛠 Административная панель (`admin.php`)

[![CRUD Operations](https://img.shields.io/badge/CRUD-Full%20Support-blue)](#управление-каналами)
[![Mass Import](https://img.shields.io/badge/Import-Batch%20Processing-green)](#массовый-импорт)
[![CSRF Protection](https://img.shields.io/badge/CSRF-Protected-orange)](#-безопасность)

- **CRUD каналов**: Добавление, редактирование и удаление каналов с привязкой к группам, логотипам и классам.
- **Управление доступом**: Создание ключей доступа (Access Keys) для клиентов, блокировка/разблокировка (бан) пользователей.
- **Массовый импорт**: 
  - Каналы: пакетное добавление/обновление через текстовый список с разделителем `|`.
  - Ключи: массовая генерация пользовательских ключей с указанием статуса и описания.
- **Группировка**: Переименование групп каналов одним действием (применяется ко всем каналам в группе).
- **Безопасность**: Встроенная защита от CSRF-атак (токены) и XSS при выводе данных.

### 📡 Генератор плейлистов (`Gena.php`)

[![M3U/M3U8](https://img.shields.io/badge/Format-M3U%2FM3U8-blueviolet)](#для-клиента)
[![Dynamic Generation](https://img.shields.io/badge/Generation-Dynamic-yellow)](#для-клиента)
[![EPG Support](https://img.shields.io/badge/EPG-Supported-green)](#для-клиента)

- Динамическая генерация **M3U/M3U8** плейлистов по запросу клиента.
- **Аутентификация**: Проверка уникального `access_key` перед выдачей плейлиста.
- **Система банов**: Если пользователь заблокирован (`status = 'banned'`), вместо плейлиста ему выдается специальная заглушка с изображением и текстом бана.
- **Поддержка EPG**: Автоматическая подстановка URL телепрограммы (`url-tvg`) в заголовок плейлиста.
- **Адаптивность**: Автоматический поиск URL потока в различных полях БД (`stream_url`, `url`, `src`, `link`, `m3u8` и др.).

### 🛡 Безопасный прокси-сервер (`Nira.php`)

[![SSRF Protection](https://img.shields.io/badge/SSRF-Protected-red)](#-безопасность)
[![IP Blacklist](https://img.shields.io/badge/Blacklist-Enabled-darkred)](#-безопасность)
[![XSS Sanitization](https://img.shields.io/badge/XSS-Sanitized-orange)](#-безопасность)

Мощный HTTP/HTTPS прокси для трансляции видеопотоков клиентам, реализующий строгие меры безопасности:
- **Защита от SSRF**: Блокировка запросов к приватным IP-диапазонам (`127.0.0.0/8`, `192.168.0.0/16`, `10.0.0.0/8` и IPv6-аналогам).
- **Blacklist**: Фильтрация нежелательных хостов и IP-адресов.
- **Валидация URL**: Проверка схемы (только HTTP/HTTPS) и расширений файлов.
- **Очистка контента**: Автоматическая санитизация HTML/текстовых ответов от XSS-инъекций.
- **Прозрачность**: Корректная передача заголовков (User-Agent, Referer и др.) и поддержка POST-запросов.

---

## 🛠 Технологии

<div align="center">

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![MariaDB](https://img.shields.io/badge/MariaDB-Supported-003545?logo=mariadb&logoColor=white)](https://mariadb.org/)
[![HTML5](https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white)](https://developer.mozilla.org/docs/Web/Guide/HTML/HTML5)
[![CSS3](https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white)](https://developer.mozilla.org/docs/Web/CSS)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES6%2B-F7DF1E?logo=javascript&logoColor=black)](https://developer.mozilla.org/docs/Web/JavaScript)

[![HLS.js](https://img.shields.io/badge/HLS.js-Video%20Streaming-black)](https://github.com/video-dev/hls.js/)
[![Plyr](https://img.shields.io/badge/Plyr-HTML5%20Player-1AB394)](https://plyr.io/)
[![cURL](https://img.shields.io/badge/cURL-HTTP%20Client-orange)](https://curl.se/)
[![PDO](https://img.shields.io/badge/PDO-Database%20Access-blue)](https://www.php.net/manual/en/book.pdo.php)
[![Prepared Statements](https://img.shields.io/badge/SQL-Prepared%20Statements-green)](#-безопасность)

</div>

---

## 📂 Структура проекта

```text
niraserver-panel/
├── 📄 index.php          # Точка входа, страница авторизации клиента
├── 📄 admin.php          # Веб-интерфейс администратора (CRUD, управление БД)
├── 📄 Gena.php           # API-эндпоинт для генерации M3U-плейлиста
├── 📄 Nira.php           # Прокси-сервер для проксирования видеопотоков
├── 📄 config.php         # Конфигурация (БД, таблицы, blacklist, EPG)
├── 📄 Nextbot.php        # Дополнительные модули и утилиты
├── 🎨 plyr.css           # Стили для HTML5-плеера
├── 🎨 plyr.js            # Библиотека пользовательского плеера
└── 🎬 hls.js             # Поддержка HTTP Live Streaming в браузере
