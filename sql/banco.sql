-- Banco de dados do projeto ContadorVisitas
-- Pode ser executado pela aba "SQL" ou "Importar" do phpMyAdmin.

CREATE DATABASE IF NOT EXISTS contador_visitas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE contador_visitas;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nome          VARCHAR(100)  NOT NULL,
  email         VARCHAR(150)  NOT NULL,
  senha_hash    VARCHAR(255)  NOT NULL,
  data_cadastro DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
