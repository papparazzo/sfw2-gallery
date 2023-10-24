
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_imagegalleries` (
    `Id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `PathId` int(10) unsigned NOT NULL,
    `UserId` int(10) unsigned NOT NULL,
    `CreationDate` date DEFAULT NULL,
    `Name` char(50) COLLATE utf8_unicode_ci NOT NULL,
    `Description` text COLLATE utf8_unicode_ci NOT NULL,
    `PreviewImage` varchar(25) COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `{TABLE_PREFIX}_imagegalleries` ADD PRIMARY KEY (`Id`);

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_newspaperarticles` (
  `Id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `PathId` int(10) UNSIGNED NOT NULL,
  `Title` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `Date` date DEFAULT NULL,
  `Source` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `UserId` int(11) NOT NULL,
  `FileName` varchar(256) COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `{TABLE_PREFIX}_newspaperarticles` ADD PRIMARY KEY (`Id`);

COMMIT;
