-- RRJ Clothing fresh database bootstrap
-- Run this once in HeidiSQL/phpMyAdmin while connected to your intended MySQL server.
-- This creates the database only. The application creates missing tables on first launch.
-- It does NOT DROP, TRUNCATE, or overwrite existing tables/data.
CREATE DATABASE IF NOT EXISTS `rrj_clothing`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
