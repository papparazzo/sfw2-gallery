-- phpMyAdmin SQL Dump
-- version 4.1.14.8
-- http://www.phpmyadmin.net
--
-- Host: db1122.1und1.de
-- Generation Time: May 07, 2018 at 11:36 PM
-- Server version: 5.5.60-0+deb7u1-log
-- PHP Version: 5.4.45-0+deb7u13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: `db361365360`
--

-- --------------------------------------------------------

--
-- Table structure for table `sfw_imagegalleries`
--

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_imagegalleries` (
    `Id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `PathId` int(10) unsigned NOT NULL,
    `UserId` int(10) unsigned NOT NULL,
    `CreationDate` date DEFAULT NULL,
    `Name` char(50) COLLATE utf8_unicode_ci NOT NULL,
    `Description` text COLLATE utf8_unicode_ci NOT NULL,
    `PreviewImage` varchar(25) COLLATE utf8_unicode_ci NOT NULL
    PRIMARY KEY (`Id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
