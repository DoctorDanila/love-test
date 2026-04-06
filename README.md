# Тестовое задание для [love.ru](https://co.love.ru)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)](https://php.net)
[![Yii Framework](https://img.shields.io/badge/Yii-2.0-40b3d8?logo=yii)](https://www.yiiframework.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-14%2B-4169E1?logo=postgresql)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7.0%2B-DC382D?logo=redis)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-24.0%2B-2496ED?logo=docker)](https://docker.com)
# Задание
Разработать сервис автодополнения (autocomplete) адресов на основе данных ФИАС (или КЛАДР).
### Функциональные требования
2. Загрузить ФИАС в PostgreSQL любым способом.
2. Реализовать autocomplete: при вводе текста отправляются запросы на сервер, сервер возвращает подходящие адреса из БД.
3. Серверная часть на PHP принимает запросы, обращается к PostgreSQL, возвращает JSON.
4. При нажатии «Выбрать» выбранный адрес отображается под полем ввода.
