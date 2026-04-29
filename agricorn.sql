-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 29, 2026 at 05:24 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `agricorn`
--

-- --------------------------------------------------------

--
-- Table structure for table `corn_profile`
--

CREATE TABLE `corn_profile` (
  `corn_profile_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `planting_date` date NOT NULL,
  `estimated_harvest_date` date NOT NULL,
  `farm_location` varchar(255) NOT NULL,
  `area_value` decimal(10,2) NOT NULL,
  `area_unit` enum('sqm','hectare') NOT NULL,
  `corn_type` varchar(100) NOT NULL,
  `corn_variety` varchar(100) NOT NULL,
  `number_of_packs` int(11) NOT NULL,
  `weight_of_packs` decimal(10,2) NOT NULL,
  `planting_density` decimal(10,2) NOT NULL,
  `seeds_per_hole` int(11) NOT NULL,
  `soil_type` varchar(100) NOT NULL,
  `estimated_seeds_range` varchar(100) NOT NULL,
  `date_created` datetime DEFAULT current_timestamp(),
  `status` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `corn_profile`
--

INSERT INTO `corn_profile` (`corn_profile_id`, `users_id`, `name`, `planting_date`, `estimated_harvest_date`, `farm_location`, `area_value`, `area_unit`, `corn_type`, `corn_variety`, `number_of_packs`, `weight_of_packs`, `planting_density`, `seeds_per_hole`, `soil_type`, `estimated_seeds_range`, `date_created`, `status`) VALUES
(6, 11, '', '2026-04-18', '2026-07-27', 'Balibago, Calatagan, Batangas', 2.00, 'hectare', 'Yellow Corn (Feeds)', 'Local Dent Corn 1', 1000, 2.00, 12.00, 3, 'loamy', '6,000,000 - 8,000,000', '2026-04-17 15:31:40', ''),
(7, 12, '', '2026-04-11', '2026-05-31', 'Balibago, Calatagan, Batangas', 5.00, 'hectare', 'Baby Corn', 'Minipop', 100, 2.00, 10.00, 5, 'chalky', '800,000 - 1,000,000', '2026-04-19 02:56:23', ''),
(8, 13, '', '2026-04-16', '2026-06-05', 'Baha, Calatagan, Batangas', 5.00, 'hectare', 'Baby Corn', 'Minipop', 100, 2.00, 10.00, 5, 'chalky', '800,000 - 1,000,000', '2026-04-19 03:06:11', ''),
(9, 6, '', '2026-04-07', '2026-06-16', 'Gulod, Calatagan, Batangas', 5.00, 'hectare', 'Sweet Corn (Native)', 'Quezon Sweet', 10, 1.00, 1.00, 2, 'sandy', '35,000 - 45,000', '2026-04-20 18:02:28', ''),
(10, 2, '', '2026-04-10', '2026-05-30', 'Carretunan, Calatagan, Batangas', 1541.00, 'sqm', 'Baby Corn', 'Early Hybrid Baby Corn 1', 1, 2.00, 1.00, 2, 'sandy', '8,000 - 10,000', '2026-04-20 18:11:12', ''),
(11, 14, '', '2026-04-20', '2026-06-29', 'Gulod, Calatagan, Batangas', 1.00, 'hectare', 'Glutinous / Waxy Corn', 'Macapuno', 1, 11.00, 1.00, 2, 'sandy', '38,500 - 49,500', '2026-04-20 18:24:31', ''),
(15, 15, 'Trisha', '2026-04-26', '2026-08-04', 'Barangay 1, Calatagan, Batangas', 1.00, 'hectare', 'Yellow Corn (Feeds)', 'Local Dent Corn 1', 4, 1.00, 2.00, 1, 'loamy', '12,000 - 16,000', '2026-04-26 21:07:18', ''),
(16, 16, 'Ryan Joseph', '2026-04-26', '2026-07-05', 'Quilitisan, Calatagan, Batangas', 2.00, 'hectare', 'Sweet Corn (Hybrid)', 'Golden Bantam F1', 5, 2.00, 1.00, 2, 'loamy', '35,000 - 45,000', '2026-04-26 21:19:52', ''),
(18, 17, 'joseph', '2026-04-28', '2026-06-17', 'Gulod, Calatagan, Batangas', 0.50, 'hectare', 'Baby Corn', 'Early Hybrid Baby Corn 1', 1, 2.00, 1.00, 2, 'loamy', '8,000 - 10,000', '2026-04-28 02:59:38', 'void'),
(19, 16, 'Ryan Joseph', '2026-04-28', '2026-07-27', 'Bagong Silang, Calatagan, Batangas', 0.50, 'hectare', 'White Corn (Field)', 'Silver Queen', 2, 2.00, 1.00, 2, 'loamy', '12,000 - 16,000', '2026-04-28 12:47:22', 'void'),
(20, 16, 'Ryan Joseph', '2026-04-28', '2026-07-27', 'Baha, Calatagan, Batangas', 0.30, 'hectare', 'White Corn (Native)', 'Local White Corn 1', 1, 2.00, 1.00, 2, 'sandy', '6,000 - 8,000', '2026-04-28 12:50:46', 'void'),
(21, 16, 'Ryan Joseph', '2026-04-28', '2026-08-01', 'Hukay, Calatagan, Batangas', 0.50, 'hectare', 'White Corn (Field)', 'Visayan White', 1, 2.00, 1.00, 2, 'peaty', '6,000 - 8,000', '2026-04-28 13:04:21', 'void'),
(22, 17, 'joseph', '2026-04-28', '2026-08-06', 'Hukay, Calatagan, Batangas', 1.00, 'hectare', 'Yellow Corn (Native)', 'Batangas Yellow', 3, 2.00, 1.00, 2, 'loamy', '18,000 - 24,000', '2026-04-28 13:23:42', 'completed'),
(24, 17, 'joseph', '2026-04-28', '2026-06-17', 'Carretunan, Calatagan, Batangas', 1.00, 'hectare', 'Baby Corn', 'Minipop', 3, 2.00, 1.00, 2, 'loamy', '24,000 - 30,000', '2026-04-28 15:14:05', 'active'),
(27, 18, 'Noreen', '2026-02-11', '2026-04-02', 'Lucsuhin, Calatagan, Batangas', 0.50, 'hectare', 'Baby Corn', 'Early Hybrid Baby Corn 1', 1, 3.00, 1.00, 2, 'clay', '12,000 - 15,000', '2026-04-28 18:07:31', 'completed'),
(28, 18, 'Noreen', '2026-04-28', '2026-07-27', 'Encarnacion, Calatagan, Batangas', 1.00, 'hectare', 'Popcorn', 'Local Popcorn', 2, 2.00, 1.00, 2, 'sandy', '16,000 - 20,000', '2026-04-28 18:11:43', 'active'),
(29, 19, 'Marlita', '2026-02-01', '2026-03-23', 'Sambungan, Calatagan, Batangas', 1.00, 'hectare', 'Baby Corn', 'Minipop', 2, 3.00, 1.00, 1, 'loamy', '24,000 - 30,000', '2026-04-29 01:07:28', 'completed'),
(30, 20, 'Trisha Mae', '2026-04-29', '2026-07-28', 'Hukay, Calatagan, Batangas', 0.20, 'hectare', 'White Corn (Native)', 'Local White Corn 2', 1, 2.00, 1.00, 1, 'loamy', '6,000 - 8,000', '2026-04-29 01:11:01', 'active'),
(31, 19, 'Marlita', '2026-04-29', '2026-08-07', 'Hukay, Calatagan, Batangas', 1.00, 'hectare', 'Yellow Corn (Native)', 'Quezon Yellow', 8, 2.00, 1.00, 1, 'sandy', '48,000 - 64,000', '2026-04-29 01:34:28', 'active'),
(32, 21, 'Prince', '2026-01-31', '2026-03-22', 'Hukay, Calatagan, Batangas', 0.50, 'hectare', 'Baby Corn', 'Early Hybrid Baby Corn 2', 4, 2.00, 1.00, 2, 'loamy', '32,000 - 40,000', '2026-04-29 07:48:29', 'completed'),
(33, 21, 'Prince', '2026-01-28', '2026-03-19', 'Carlosa, Calatagan, Batangas', 1.00, 'hectare', 'Baby Corn', 'Early Hybrid Baby Corn 1', 2, 2.00, 1.00, 2, 'loamy', '16,000 - 20,000', '2026-04-29 08:02:08', 'completed'),
(34, 21, 'Prince', '2026-04-29', '2026-06-18', 'Gulod, Calatagan, Batangas', 1.00, 'hectare', 'Baby Corn', 'Minipop', 5, 2.00, 1.00, 2, 'loamy', '40,000 - 50,000', '2026-04-29 09:00:02', 'active'),
(35, 22, 'Ariane12', '2026-04-29', '2026-08-02', 'Biga, Calatagan, Batangas', 1.00, 'hectare', 'Popcorn', 'Strawberry Popcorn', 5, 2.00, 1.00, 2, 'loamy', '40,000 - 50,000', '2026-04-29 09:34:44', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `costing`
--

CREATE TABLE `costing` (
  `costing_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `expense_type` varchar(100) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `date_created` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `costing`
--

INSERT INTO `costing` (`costing_id`, `users_id`, `expense_type`, `cost`, `date_created`) VALUES
(4, 2, 'Seeds', 400.00, '2026-04-13 00:24:03'),
(5, 2, 'Labor', 3000.00, '2026-04-13 00:24:03'),
(6, 9, 'Seeds', 1000.00, '2026-04-14 20:56:32'),
(7, 9, 'Labor', 4000.00, '2026-04-14 20:56:32'),
(13, 12, 'Seeds', 5000.00, '2026-04-19 03:14:02'),
(14, 12, 'Labor', 1000.00, '2026-04-19 03:14:02'),
(15, 12, 'tubig', 10000.00, '2026-04-19 03:14:02'),
(16, 12, 'aSFASFASF', 50000.00, '2026-04-19 03:14:02'),
(19, 14, 'Seeds', 500.00, '2026-04-20 18:35:22'),
(20, 14, 'Labor', 2000.00, '2026-04-20 18:35:22'),
(21, 14, 'Spray', 2000.00, '2026-04-20 18:35:22'),
(47, 3, 'Seeds', 2000.00, '2026-04-25 13:06:09'),
(48, 3, 'Labor', 0.00, '2026-04-25 13:06:09'),
(70, 6, 'Seeds', 5000.00, '2026-04-28 01:58:16'),
(71, 6, 'Labor', 6000.00, '2026-04-28 01:58:16'),
(76, 17, 'Seeds', 200.00, '2026-04-28 13:23:59'),
(77, 17, 'Labor', 7000.00, '2026-04-28 13:23:59'),
(88, 22, 'Seeds', 2000.00, '2026-04-29 09:35:45'),
(89, 22, 'Labor', 2000.00, '2026-04-29 09:35:45'),
(90, 22, 'Watering Task', 100.00, '2026-04-29 09:35:45');

-- --------------------------------------------------------

--
-- Table structure for table `guide_module`
--

CREATE TABLE `guide_module` (
  `guide_id` int(11) NOT NULL,
  `module_title` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `short_description` text NOT NULL,
  `guide_content` longtext DEFAULT NULL,
  `external_link` varchar(255) DEFAULT NULL,
  `date_created` datetime DEFAULT current_timestamp(),
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `guide_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guide_module`
--

INSERT INTO `guide_module` (`guide_id`, `module_title`, `category`, `short_description`, `guide_content`, `external_link`, `date_created`, `last_updated`, `guide_file`) VALUES
(10, 'Soil Management', 'Soil Management', 'Soil management is a key component to the success of site-specific cropping systems management. It starts with a farmer\'s capacity to vary tillage and inputs according to soil conditions and needs.', NULL, 'https://www.sciencedirect.com/topics/agricultural-and-biological-sciences/soil-management', '2026-04-16 22:45:52', '2026-04-16 22:45:52', NULL),
(11, 'Soil Management', 'Soil Management', 'Soil management encompasses a number of strategies used by farmers and ranchers to protect soil resources, one of their most valuable assets. By practicing soil conservation, including appropriate soil preparation methods, they reduce soil erosion and increase soil stabilization.', NULL, 'https://www.sare.org/sare-category/soil-management/', '2026-04-16 22:47:52', '2026-04-16 22:47:52', NULL),
(12, 'Soil Management', 'Soil Management', 'A primary resource for food production and the most important tool for every farmer. Successful farming depends on the quality of soil, which provides water and essential nutrients to crops. Rich and healthy soil, combined with the appropriate amount of water and sunlight can significantly contribute to a successful farming season and meeting yield quotas.', NULL, 'https://www.agrivi.com/blog/five-ways-to-manage-the-soil-for-planting/#Proper_Tillage_Preparing_The_Soil_For_Growing_Crops', '2026-04-16 22:49:22', '2026-04-16 22:50:58', NULL),
(13, 'Corn fertilization', 'Fertilizer', 'Profitable corn production requires an adequate soil fertility program. Insufficient nutrients will lower yields; excess nutrients will lower profit margins and may damage the environment through nutrient runoff and leaching.', NULL, NULL, '2026-04-16 22:57:47', '2026-04-16 22:57:47', '../data/guides/guide_69e0f8ebe9c625.21182004.pdf'),
(14, 'Corn Techno Guide', 'General', 'The Cagayan Valley Region plays a very crucial role in the country’s corn production. Consistently and among all regions, we are leading as we share about 21% of the total national production.', NULL, NULL, '2026-04-16 23:01:31', '2026-04-16 23:01:31', '../data/guides/guide_69e0f9cb601de3.63297636.pdf'),
(15, 'Fertilizers for Corn', 'Fertilizer', 'These general fertilizer guidelines should only be used in limited circumstances when a complete soil test has not been taken as the tables in this section are condensed for simplicity. More information is incorporated into the computerized guideline system than is considered in the general tables here.', NULL, 'https://cals.cornell.edu/field-crops/corn/fertilizers-for-corn', '2026-04-16 23:03:20', '2026-04-16 23:03:20', NULL),
(16, 'Insect Pest For Corn', 'Pest Control', 'INSECT PESTS AND DISEASES OF CORN, NATURAL ENEMIES AND MANAGEMENT', NULL, NULL, '2026-04-16 23:08:55', '2026-04-16 23:08:55', '../data/guides/guide_69e0fb87cc0521.16556356.pdf'),
(17, 'Non-chemical Pest Management in Corn Production', 'Pest Control', 'This field guide is designed to make the control of corn pests as easy as possible. Each\r\npest included has a brief description of its lifecycle, damage it causes, and the control measures. It is very important to know how the insect/mite pest develops because the adult does not always cause the damage and sometimes it is not even found where the damage\r\noccurred.', NULL, NULL, '2026-04-16 23:11:35', '2026-04-16 23:11:35', '../data/guides/guide_69e0fc27e47251.05747619.pdf'),
(18, 'Irrigation Guide', 'Irrigation', 'Irrigation is vital to produce acceptable quality and yield of crops on arid climate croplands. Supplemental irrigation is also vital to produce acceptable quality and yield of crops on croplands in semi-arid and subhumid climates during seasonal droughty periods.', NULL, NULL, '2026-04-16 23:14:44', '2026-04-16 23:14:44', '../data/guides/guide_69e0fce4e9d502.72053873.pdf'),
(19, 'Revitalizing Philippine Irrigation', 'Irrigation', 'Irrigation is one of the key infrastructures that can successfully increase farm productivity and incomes, and thus boost socioeconomic progress in the countryside.', NULL, NULL, '2026-04-16 23:17:36', '2026-04-16 23:17:36', '../data/guides/guide_69e0fd9008a570.52918452.pdf'),
(20, 'Corn Farming Process – Growing, Harvesting and Storing Corn Crops', 'Harvesting', 'Corn might be one of the most versatile commodities in existence. It’s the primary feed grain in the United States, making up more than 95% of production and use. More than 90 million acres of U.S. land exclusively grow corn, with much of that farmland situated in the central part of the country. Though many farmers grow corn as livestock feed, it can also make oil, sweetener, starch, alcohol and ethanol.', NULL, 'https://lcdmcorp.com/grain-flow-101/corn-farming-process-growing-harvesting-and-storing-corn-crops/', '2026-04-16 23:19:34', '2026-04-16 23:19:34', NULL),
(21, 'Corn Production Guide', 'Soil Management', 'One factor that affects plant growth is land preparation.  Properly prepared fields promote good root development and better weed, pest and disease management.', NULL, 'https://www.juanmagsasaka.com/2020/12/corn-production-guide-land-prep.html', '2026-04-16 23:21:02', '2026-04-16 23:21:02', NULL),
(22, 'Corn Leaf Disease', 'Corn Leaf Disease', 'During the early stages of disease development, several different leaf diseases may exhibit similar symptoms, making identification difficult', NULL, 'https://www.corn-states.com/app/uploads/2018/07/corn-leaf-diseases.pdf', '2026-04-16 23:25:09', '2026-04-16 23:25:09', NULL),
(23, 'CORN PRODUCTION GUIDE', 'General', 'Ang mais ay isa sa mga pinaka-importanteng produktong\r\npangagrikultura ng Pilipinas. Ito ay ginagamit bilang pagkain ng tao, at\r\npangunahing sangkap sa paggawa ng mga pagkain ng mga hayop.\r\nAng mais ay isa sa mga pangunahing itinatanim ng mga magsasaka\r\nlalung-lalo na sa katimugang bahagi ng Mindanao. Ang babasahing ito\r\nay inilathala upang sa gayon ay matulungan ang mga magsasaka\r\nupang sila ay magkaroon ng mataas na ani.', NULL, NULL, '2026-04-16 23:30:43', '2026-04-16 23:30:43', '../data/guides/guide_69e100a30df4c1.81074583.pdf'),
(24, 'TRAINING MANUAL ON SUSTAINABLE CORN PRODUCTION IN SLOPING AREAS', 'General', 'Corn has become an emerging cash crop due to the introduction of various technologies in corn production. Many idle and barren lands were used to plant corn and eventually expand to sloping lands and protected areas.', NULL, NULL, '2026-04-16 23:32:07', '2026-04-16 23:32:07', '../data/guides/guide_69e100f710eef7.87293591.pdf'),
(25, 'Corn Farming in the Philippines : Complete Guide from Seeds to Harvest', 'General', 'Corn Farming in the Philippines : Complete Guide from Seeds to Harvest', NULL, 'https://www.youtube.com/watch?v=HQQ8AHOxNdw', '2026-04-16 23:32:52', '2026-04-16 23:32:52', NULL),
(26, 'Corn Production Handbook', 'General', 'Corn Production Handbook', NULL, NULL, '2026-04-16 23:33:51', '2026-04-16 23:33:51', '../data/guides/guide_69e1015fb7a786.56225038.pdf'),
(27, 'Corn Production Manual', 'General', 'Corn Production Manual', NULL, NULL, '2026-04-16 23:34:51', '2026-04-16 23:34:51', '../data/guides/guide_69e1019b027609.83526372.pdf'),
(28, 'Land Selection', 'Soil Management', 'Land Selection', NULL, NULL, '2026-04-16 23:36:05', '2026-04-16 23:36:05', '../data/guides/guide_69e101e5716250.38249096.pdf'),
(29, 'Corn Fertilizer Management | Syngenta PH', 'Fertilizer', 'Corn Fertilizer Management | Syngenta PH', NULL, 'https://www.youtube.com/watch?v=SIsBnehVgXQ', '2026-04-16 23:37:02', '2026-04-16 23:37:02', NULL),
(30, 'Growing and Harvesting Corn in the Philippines', 'Harvesting', 'Growing and Harvesting Corn in the Philippines', NULL, 'https://www.youtube.com/watch?v=iiVLyndKync', '2026-04-16 23:37:50', '2026-04-16 23:37:50', NULL),
(31, 'PEST MANAGEMENT GUIDE', 'Pest Control', 'PEST MANAGEMENT\r\nGUIDE', NULL, NULL, '2026-04-16 23:39:07', '2026-04-16 23:39:07', '../data/guides/guide_69e1029b211a13.90516944.pdf'),
(32, 'Corn Insect Pest', 'Pest Control', 'Corn Insect Pest', NULL, NULL, '2026-04-16 23:41:16', '2026-04-16 23:41:16', '../data/guides/guide_69e1031ccb64c7.50505926.pdf'),
(33, 'Corn Insect Pest', 'Pest Control', 'Corn Insect Pest', NULL, NULL, '2026-04-16 23:41:56', '2026-04-16 23:41:56', '../data/guides/guide_69e10344e0d623.36311218.pdf'),
(34, 'Gray Leaf Spot', 'Corn Leaf Disease', 'Gray Leaf Spot', NULL, NULL, '2026-04-16 23:42:56', '2026-04-16 23:42:56', '../data/guides/guide_69e10380181bb9.71719856.pdf'),
(35, 'Common and Southern Rusts', 'Corn Leaf Disease', 'Common and Southern Rusts', NULL, NULL, '2026-04-16 23:43:54', '2026-04-16 23:43:54', '../data/guides/guide_69e103bac10077.73669276.pdf'),
(36, 'Northern Corn Leaf Blight', 'Corn Leaf Disease', 'Northern Corn Leaf Blight', NULL, NULL, '2026-04-16 23:44:48', '2026-04-16 23:44:48', '../data/guides/guide_69e103f06ef273.96511179.pdf'),
(37, 'Northern Corn Leaf Blight', 'Corn Leaf Disease', 'Northern Corn Leaf Blight', NULL, NULL, '2026-04-16 23:45:19', '2026-04-16 23:45:19', '../data/guides/guide_69e1040fe4c7f1.56868772.pdf'),
(38, 'Gray Leaf Spot', 'Corn Leaf Disease', 'Gray Leaf Spot', NULL, NULL, '2026-04-16 23:46:29', '2026-04-16 23:46:29', '../data/guides/guide_69e104555e0240.04226067.pdf');

-- --------------------------------------------------------

--
-- Table structure for table `lifecycle_journal`
--

CREATE TABLE `lifecycle_journal` (
  `lifecycle_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `stage_number` int(11) NOT NULL,
  `journal_text` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lifecycle_journal`
--

INSERT INTO `lifecycle_journal` (`lifecycle_id`, `users_id`, `stage_number`, `journal_text`, `image_path`, `updated_at`) VALUES
(44, 6, 1, '', '../data/Lifecycle Stage Image/crop_6_1777294377_e5b21fd1.png', '2026-04-27 12:52:57'),
(45, 17, 1, '', '../data/Lifecycle Stage Image/crop_17_1777316416_0409eaff.png', '2026-04-27 19:00:16'),
(47, 16, 1, '', '../data/Lifecycle Stage Image/crop_16_1777351749_a8b880ad.png', '2026-04-28 04:49:09'),
(48, 22, 1, 'dsfghioj', '../data/Lifecycle Stage Image/crop_22_1777426594_854f3cba.png', '2026-04-29 01:36:37');

-- --------------------------------------------------------

--
-- Table structure for table `pest_and_disease_results`
--

CREATE TABLE `pest_and_disease_results` (
  `id` int(10) UNSIGNED NOT NULL,
  `users_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `result` varchar(255) NOT NULL,
  `image` varchar(255) NOT NULL,
  `action_recommended` text NOT NULL,
  `related_guides` longtext NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pest_and_disease_results`
--

INSERT INTO `pest_and_disease_results` (`id`, `users_id`, `name`, `result`, `image`, `action_recommended`, `related_guides`, `date_created`) VALUES
(8, 16, 'Ryan Joseph', 'Common Rust', '20260426_213001_scan_c4383682.png', 'Check leaves for rust pustules.\r\nRemove severely infected leaves if possible.\r\nImprove airflow around plants.\r\nApply approved fungicide.\r\nMonitor disease spread weekly.', 'Gray Leaf Spot, Northern Corn Leaf Blight, Common and Southern Rusts', '2026-04-27 03:30:01'),
(9, 16, 'Ryan Joseph', 'Healthy', '20260426_213507_screenshot_2026-04-25_141341_e6c08cb7.png', 'Continue regular crop monitoring.\r\nMaintain proper irrigation schedule.\r\nApply scheduled fertilizer as needed.\r\nKeep field free from weeds.\r\nInspect plants weekly for early problems.', 'Soil Management', '2026-04-27 03:35:07'),
(10, 16, 'Ryan Joseph', 'Healthy', '20260426_213922_screenshot_2026-04-25_141341_ed30cd73.png', 'Continue regular crop monitoring.\r\nMaintain proper irrigation schedule.\r\nApply scheduled fertilizer as needed.\r\nKeep field free from weeds.\r\nInspect plants weekly for early problems.', 'Soil Management', '2026-04-27 03:39:22'),
(11, 16, 'Ryan Joseph', 'Healthy', '20260426_213944_screenshot_2026-04-25_141341_f2828096.png', 'Continue regular crop monitoring.\r\nMaintain proper irrigation schedule.\r\nApply scheduled fertilizer as needed.\r\nKeep field free from weeds.\r\nInspect plants weekly for early problems.', 'Soil Management', '2026-04-27 03:39:44'),
(12, 16, 'Ryan Joseph', 'Healthy', '20260426_214106_screenshot_2026-04-25_141341_8fd8769e.png', 'Continue regular crop monitoring.\r\nMaintain proper irrigation schedule.\r\nApply scheduled fertilizer as needed.\r\nKeep field free from weeds.\r\nInspect plants weekly for early problems.', 'Soil Management', '2026-04-27 03:41:06'),
(13, 17, 'joseph', 'Common Rust', '20260805_073150_screenshot_2026-04-26_003934_e1c11824.png', 'Check leaves for rust pustules.\r\nRemove severely infected leaves if possible.\r\nImprove airflow around plants.\r\nApply approved fungicide.\r\nMonitor disease spread weekly.', 'Gray Leaf Spot, Northern Corn Leaf Blight, Common and Southern Rusts', '2026-08-05 13:31:50'),
(14, 22, 'Ariane12', 'Healthy', '20260429_033850_screenshot_2026-04-25_141341_937a1d73.png', 'Continue regular crop monitoring.\r\nMaintain proper irrigation schedule.\r\nApply scheduled fertilizer as needed.\r\nKeep field free from weeds.\r\nInspect plants weekly for early problems.', 'Soil Management', '2026-04-29 09:38:50');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `users_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `name` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `address` varchar(50) NOT NULL,
  `password` varchar(500) NOT NULL,
  `confirm_password` varchar(500) NOT NULL,
  `date_created` date NOT NULL,
  `last_login_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`users_id`, `role`, `name`, `username`, `address`, `password`, `confirm_password`, `date_created`, `last_login_date`) VALUES
(1, 'Admin', 'Admin', 'agricorn@admin', 'agricorn@admin', 'agricorn@admin', 'agricorn@admin', '2026-03-13', '2026-04-29 09:20:44'),
(2, 'Farmer', 'Farmer1', 'Farmer1', 'Farmer1', '$2y$10$/8r6NVX0HZX3TCg17TpW9.6aphK14C6c98j4DgJ1BXj.A8dV3F7ee', '$2y$10$/8r6NVX0HZX3TCg17TpW9.6aphK14C6c98j4DgJ1BXj.A8dV3F7ee', '2026-03-13', '2026-04-28 23:22:10'),
(3, 'Farmer', 'Farmer2', 'Farmer2', 'Sambungan, Calatagan, Batangas', '$2y$10$dwklIlzFgE3HQwaTuhsEGeFKKCPKaggyDKVeK03dEDlfEWSLBTpKu', '$2y$10$dwklIlzFgE3HQwaTuhsEGeFKKCPKaggyDKVeK03dEDlfEWSLBTpKu', '2026-04-01', '2026-04-28 23:22:24'),
(4, 'Farmer', 'Farmer3', 'Farmer3', 'Sambungan, Calatagan, Batangas', '$2y$10$zHdEEV53VaBbPxa5LvnTzefOiLbJjmDFVKS2H8fQ8sdPiPVqT9D1S', '$2y$10$zHdEEV53VaBbPxa5LvnTzefOiLbJjmDFVKS2H8fQ8sdPiPVqT9D1S', '2026-04-01', NULL),
(5, 'farmer', 'Farmer4', 'Farmer4', 'Sambungan, Calatagan, Batangas', '$2y$10$t5MyQzON8K0gwso1aALI4u36HQc4pTfyJd7d0QaR6pTYooVLYIpsu', '$2y$10$t5MyQzON8K0gwso1aALI4u36HQc4pTfyJd7d0QaR6pTYooVLYIpsu', '2026-04-07', '2026-04-24 12:10:34'),
(6, 'farmer', 'Farmer5', 'Farmer5', 'Sambungan, Calatagan, Batangas', '$2y$10$lWE5a3MTjojTGcM5AaYQpuT0Pceyb35wtYwkPB11ZH2wG5L1pghZW', '$2y$10$lWE5a3MTjojTGcM5AaYQpuT0Pceyb35wtYwkPB11ZH2wG5L1pghZW', '2026-04-07', '2026-04-28 13:47:03'),
(8, 'farmer', 'Farmer6', 'Farmer6', 'Sambungan, Calatagan, Batangas', '$2y$10$8ccNTXTjIAamsTTeVDr97uJOIo9PQ1lM/939/fi7rkYDgnJ/RBAjm', '$2y$10$8ccNTXTjIAamsTTeVDr97uJOIo9PQ1lM/939/fi7rkYDgnJ/RBAjm', '2026-04-10', '2026-04-11 02:02:34'),
(9, 'farmer', 'Farmer7', 'Farmer7', 'Sambungan, Calatagan, Batangas', '$2y$10$l0yV6m8GlURSTFASKtMRRetdPtnsrstFMWLIlPizM0PRsA3lmD8dC', '$2y$10$l0yV6m8GlURSTFASKtMRRetdPtnsrstFMWLIlPizM0PRsA3lmD8dC', '2026-04-14', '2026-04-24 12:09:32'),
(11, 'farmer', 'pierre', 'pierre', 'calatagan', '$2y$10$Ek350VBv1nu5a.PZJ3il7O/oMiphmdF1AiVPwEXB42bVvsncq1076', '$2y$10$Ek350VBv1nu5a.PZJ3il7O/oMiphmdF1AiVPwEXB42bVvsncq1076', '2026-04-17', '2026-04-19 03:10:20'),
(12, 'farmer', 'Edriz', 'Edriz', 'calatagan', '$2y$10$NEVsFfToo8ohTQr.WfMCYei/cAM8QV8jk38yzwWwb5vzhFnRkrRbG', '$2y$10$NEVsFfToo8ohTQr.WfMCYei/cAM8QV8jk38yzwWwb5vzhFnRkrRbG', '2026-04-18', '2026-04-19 03:10:46'),
(13, 'farmer', 'Celso', 'Celso', 'calatagan', '$2y$10$8/0SXOmzf9eJ.RnVRD79TOhfj4ooOeHBcocPgrXTx5b895T7MFhB.', '$2y$10$8/0SXOmzf9eJ.RnVRD79TOhfj4ooOeHBcocPgrXTx5b895T7MFhB.', '2026-04-18', '2026-04-19 03:08:26'),
(14, 'farmer', 'ryan', 'ryan', 'Sambungan, Calatagan, Batangas', '$2y$10$cOAhRv6YNp5JH.X.PLvR4OlVobn2Gzadeg7uTopkKLPqLWa96c.ui', '$2y$10$cOAhRv6YNp5JH.X.PLvR4OlVobn2Gzadeg7uTopkKLPqLWa96c.ui', '2026-04-20', '2026-04-20 18:21:15'),
(15, 'farmer', 'Trisha', 'Trisha', 'Sambungan, Calatagan, Batangas', '$2y$10$X5XV145fOiJPGSoh/ZCqD.Px.JNlS5XvfLatiXvH40uivScI4wzza', '$2y$10$X5XV145fOiJPGSoh/ZCqD.Px.JNlS5XvfLatiXvH40uivScI4wzza', '2026-04-26', '2026-04-26 19:51:47'),
(16, 'farmer', 'Ryan Joseph', 'ryann', 'Sambungan, Calatagan, Batangas', '$2y$10$2qOIYqXj70iv3KjCQuaQ4OCAu9SOJ8I3wF6y4RIrPhqrT/IgCz/AS', '$2y$10$2qOIYqXj70iv3KjCQuaQ4OCAu9SOJ8I3wF6y4RIrPhqrT/IgCz/AS', '2026-04-26', '2026-04-28 12:46:43'),
(17, 'farmer', 'joseph', 'joseph', 'Sambungan, Calatagan, Batangas', '$2y$10$VSifoA7dg88XH.c6pKmq1uUcoccNOWlo9trdNLx8tJzK18uzIPjo2', '$2y$10$VSifoA7dg88XH.c6pKmq1uUcoccNOWlo9trdNLx8tJzK18uzIPjo2', '2026-04-27', '2026-04-28 23:22:37'),
(18, 'farmer', 'Noreen', 'noreen', 'Hukay, Calatagan, Batangas', '$2y$10$9ph2rzFXUI49v7HN1tD9ou9eg9lWjK2qZcLkPrfN0EGFLOU0KM4dO', '$2y$10$9ph2rzFXUI49v7HN1tD9ou9eg9lWjK2qZcLkPrfN0EGFLOU0KM4dO', '2026-04-28', '2026-04-29 01:02:28'),
(19, 'farmer', 'Marlita', 'Marlita', 'Sambungan, Calatagan, Batangas', '$2y$10$WF52jYh3VNvon.mriUeV2O5oX//4owTREbdfUiX/xsYhiCMOkwodW', '$2y$10$WF52jYh3VNvon.mriUeV2O5oX//4owTREbdfUiX/xsYhiCMOkwodW', '2026-04-28', '2026-04-29 09:23:18'),
(20, 'farmer', 'Trisha Mae', 'Trishamae', 'Encarnacion, Calatagan, Batangas', '$2y$10$syQxl6GllCqC3x9wkn7a4.rXwBdSv9JNyG1O9W6eqtSIIjUK7s.gC', '$2y$10$syQxl6GllCqC3x9wkn7a4.rXwBdSv9JNyG1O9W6eqtSIIjUK7s.gC', '2026-04-28', '2026-04-29 01:10:28'),
(21, 'farmer', 'Prince', 'Prince', 'Carretunan, Calatagan, Batangas', '$2y$10$RGe9OGGbgiDBG3hx2cBlnOwe58bkwln.qZwurdaMhZ0y4hOT5w5qi', '$2y$10$RGe9OGGbgiDBG3hx2cBlnOwe58bkwln.qZwurdaMhZ0y4hOT5w5qi', '2026-04-29', '2026-04-29 08:58:04'),
(22, 'farmer', 'Ariane12', 'Ariane12', 'Biga, Calatagan, Batangas', '$2y$10$zPgmV/E4kyIUy6n2MARthOt.4ati4KOAXKGCReBr72Uxqm9Srn44u', '$2y$10$zPgmV/E4kyIUy6n2MARthOt.4ati4KOAXKGCReBr72Uxqm9Srn44u', '2026-04-29', '2026-04-29 09:32:31');

-- --------------------------------------------------------

--
-- Table structure for table `void_account`
--

CREATE TABLE `void_account` (
  `void_id` int(11) NOT NULL,
  `corn_profile_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `date_void` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `void_account`
--

INSERT INTO `void_account` (`void_id`, `corn_profile_id`, `users_id`, `name`, `reason`, `notes`, `date_void`) VALUES
(1, 19, 16, 'Ryan Joseph', 'Wrong planting details', '', '2026-04-28 12:49:46'),
(2, 20, 16, 'Ryan Joseph', 'Restarting planting cycle', '', '2026-04-28 13:00:41'),
(3, 21, 16, 'Ryan Joseph', 'Restarting planting cycle', '', '2026-04-28 13:06:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `corn_profile`
--
ALTER TABLE `corn_profile`
  ADD PRIMARY KEY (`corn_profile_id`),
  ADD KEY `fk_corn_user` (`users_id`);

--
-- Indexes for table `costing`
--
ALTER TABLE `costing`
  ADD PRIMARY KEY (`costing_id`),
  ADD KEY `fk_costing_user` (`users_id`);

--
-- Indexes for table `guide_module`
--
ALTER TABLE `guide_module`
  ADD PRIMARY KEY (`guide_id`);

--
-- Indexes for table `lifecycle_journal`
--
ALTER TABLE `lifecycle_journal`
  ADD PRIMARY KEY (`lifecycle_id`),
  ADD UNIQUE KEY `user_stage_idx` (`users_id`,`stage_number`);

--
-- Indexes for table `pest_and_disease_results`
--
ALTER TABLE `pest_and_disease_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pdr_user_date` (`users_id`,`date_created`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`users_id`);

--
-- Indexes for table `void_account`
--
ALTER TABLE `void_account`
  ADD PRIMARY KEY (`void_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `corn_profile`
--
ALTER TABLE `corn_profile`
  MODIFY `corn_profile_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `costing`
--
ALTER TABLE `costing`
  MODIFY `costing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `guide_module`
--
ALTER TABLE `guide_module`
  MODIFY `guide_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `lifecycle_journal`
--
ALTER TABLE `lifecycle_journal`
  MODIFY `lifecycle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `pest_and_disease_results`
--
ALTER TABLE `pest_and_disease_results`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `users_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `void_account`
--
ALTER TABLE `void_account`
  MODIFY `void_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `corn_profile`
--
ALTER TABLE `corn_profile`
  ADD CONSTRAINT `fk_corn_user` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `costing`
--
ALTER TABLE `costing`
  ADD CONSTRAINT `fk_costing_user` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
