-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 11, 2026 at 12:46 AM
-- Server version: 10.5.29-MariaDB
-- PHP Version: 8.4.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `admin_bea`
--

-- --------------------------------------------------------

--
-- Table structure for table `book`
--

CREATE TABLE `book` (
  `cat_title` varchar(0) DEFAULT NULL,
  `recipe_title` varchar(0) DEFAULT NULL,
  `recipe_ingredients` varchar(0) DEFAULT NULL,
  `recipe_method` varchar(0) DEFAULT NULL,
  `recipe_note` varchar(0) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `book_toc`
--

CREATE TABLE `book_toc` (
  `cat_title` varchar(0) DEFAULT NULL,
  `recipe_title` varchar(0) DEFAULT NULL,
  `recipe_ingredients` varchar(0) DEFAULT NULL,
  `recipe_method` varchar(0) DEFAULT NULL,
  `recipe_note` varchar(0) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `catrecipe`
--

CREATE TABLE `catrecipe` (
  `catID` int(11) NOT NULL,
  `cat_title` varchar(200) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `catrecipe`
--

INSERT INTO `catrecipe` (`catID`, `cat_title`) VALUES
(41, 'slow cooker recipes'),
(2, 'Pizza'),
(17, 'meats and gravies'),
(5, 'turkey'),
(9, 'BARS CANDIES AND MISC'),
(54, 'CHICKEN'),
(21, 'fish'),
(22, 'vegetables and cheese sauce'),
(34, 'appetizers and hors d\'oeuvres'),
(36, 'Tassimo Recipes'),
(40, 'DESERTS'),
(42, 'breads and bread maker machine'),
(44, 'Frostings'),
(45, 'cakes'),
(51, 'misc'),
(55, 'SALADS'),
(56, 'DIPS'),
(57, 'PASTA'),
(58, 'COOKIES'),
(59, 'MUFFINS'),
(60, 'VEGAN'),
(61, 'POTATOES'),
(62, 'soups'),
(63, 'weight watchers'),
(64, 'pies'),
(65, 'drinks'),
(66, 'chinese'),
(67, 'BREAKFAST'),
(68, 'The doctor\'s diet');

-- --------------------------------------------------------

--
-- Table structure for table `login_users`
--

CREATE TABLE `login_users` (
  `User_ID` int(11) NOT NULL,
  `User_name` varchar(30) NOT NULL,
  `PassWd` varchar(30) NOT NULL,
  `FirstName` varchar(30) DEFAULT NULL,
  `LastName` varchar(30) DEFAULT NULL,
  `Group_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `login_users`
--

INSERT INTO `login_users` (`User_ID`, `User_name`, `PassWd`, `FirstName`, `LastName`, `Group_ID`) VALUES
(1, 'admin', 'bea', 'Béatrice', 'Minogue', 100),
(2, 'minogue', 'kids', 'guest', 'guest', 10);

-- --------------------------------------------------------

--
-- Table structure for table `menu`
--

CREATE TABLE `menu` (
  `Menu_ID` int(11) NOT NULL,
  `Menu_caption` varchar(255) DEFAULT NULL,
  `Menu_URL` varchar(255) DEFAULT NULL,
  `Menu_parent_ID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `menu`
--

INSERT INTO `menu` (`Menu_ID`, `Menu_caption`, `Menu_URL`, `Menu_parent_ID`) VALUES
(1, 'Home', 'index.php', NULL),
(2, 'Recipes', 'recipes.php', NULL),
(3, 'Categories', 'categories.php', NULL),
(6, 'Print recipes', 'recipe_book_2.php', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `recipe`
--

CREATE TABLE `recipe` (
  `recipeID` int(11) NOT NULL,
  `recipe_title` varchar(200) NOT NULL DEFAULT '',
  `recipe_ingredients` text NOT NULL,
  `recipe_method` text NOT NULL,
  `recipe_note` text NOT NULL,
  `catID` int(11) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `recipe`
--

INSERT INTO `recipe` (`recipeID`, `recipe_title`, `recipe_ingredients`, `recipe_method`, `recipe_note`, `catID`) VALUES
(621, 'BLACK Magic Cake ', '\r\nIngredients: \r\n2 cups sugar\r\n1 3/4 cups flour\r\n3/4 cups cocoa\r\n2 tsp soda\r\n1 tsp baking powder\r\n1/2 tsp salt\r\n2 eggs\r\n1 cup sour milk\r\n1 cup strong coffee \r\n1/2 cup vegetable oil\r\n1 tsp vanilla \r\n', 'Method: \r\nmix all the dry ingredients in a large mixing bowl.. in another bowl add the eggs, milk, coffee, veg oil and vanilla/make a well in the dry ingredients bowl then pour the egg mixture into it Beat on medium speed for 2 minutes (batter will be thin). Pour batter into greased and floured pans. Bake at 350f for 35 to 40 minutes. \r\nNotes: \r\nto sour the milk add vinegar to it i usually use this recipe to make 3 round cakes for my black forest cake.so i think a 9 x13 pan would be good enjoy\r\nbea \r\n', '\r\nvery moist', 45),
(622, 'MOMS WHITE CAKE', '\r\n1/2 cup butter\r\n1 cup sugar\r\n2 eggs\r\npinch of salt\r\n', 'MIX  ALL ABOVE INGREDIENTS TOGETHER\r\n2 cups flour\r\n1 cup milk\r\n2 tsp baking powder\r\n1 tsp vanilla\r\nThen mix 2 cups flour with 2 tsp baking powder ,then add  flour mixture to butter mixture alternating with the milk mixture and vanilla\r\nBAKE AT 350 DEGREE TILL TOOTHPICK INSERTED COMES OUT CLEAN BETWEEN 20 TO 30 MINUTES\r\n', 'good', 45),
(623, 'PUDDING CHAUMERE', '\r\n1 cup maple syrup\r\n1 cup brown sugar\r\n1 cup boiling water\r\n1/4 cup  butter\r\n\r\nCAKE\r\n1 1/2 cup flour\r\n1 tsp baking powder\r\n1/4 cup butter\r\n1 cup sugar\r\n1 cup milk\r\n', 'Mix the cake batter together and pour in greased baking cake pan,then  pour the maple  syrup recipe on top of the cake and bake at 325 degrees for 45 minutes and enjoy\r\n', 'great', 45),
(624, 'cauliflower pizza', 'Ingredients\r\n1 large head cauliflower\r\n2 large eggs\r\n1 c. shredded mozzarella\r\n1/4 c. shredded Parmesan\r\n3 tbsp. finely chopped fresh basil\r\n1 tbsp. garlic powder\r\nkosher salt\r\nFreshly ground black pepper\r\n1/2 c. marinara\r\n1/4 c. mini pepperoni\r\n', 'Directions\r\n1. Preheat oven to 400 degrees F. Grate cauliflower on the small side of box grater to form fine crumbs. Transfer to a large bowl.\r\n2. Add egg, 1/3 mozzarella, Parmesan, 2 tablespoons basil, and garlic powder and season with salt and pepper. Form into small patties (they will be wet) and place on a greased baking sheet. Bake until golden, 20 minutes.\r\n3. Top each cauli patty with a thin layer of marinara, remaining mozz, and mini pepperoni and bake until cheese melts and pepperoni crisps, 5 to 7 minutes more.\r\n4. Garnish with remaining basil and serve.\r\nMore recipes like this\r\n', 'ok', 2),
(625, 'Cheesecake Pumpkin Pie', '2 (8 ounce) packages cream cheese, softened\r\n1/2 cup white sugar\r\n1/2 teaspoon vanilla extract\r\n2 eggs\r\n1 (9 inch) graham cracker crust or regular pie crust\r\n1/2 cup pumpkin puree\r\n1/2 teaspoon ground cinnamon\r\n1 pinch ground cloves\r\n1 pinch ground nutmeg\r\n', 'Preheat oven to 325 degrees. In a large bowl, combine cream cheese, sugar and vanilla. Beat until smooth. Blend in eggs one at a time. Remove 1 cup of batter and spread into bottom of crust; set aside.\r\nAdd pumpkin, cinnamon, cloves and nutmeg to the remaining batter and stir gently until well blended. Carefully spread over the batter in the crust.\r\nBake in preheated oven for 35 to 40 minutes, or until center is almost set. Allow to cool, then refrigerate for 3 hours or overnight. Top with whipped cream if desired.\r\n', 'awesome', 40),
(626, 'The Perfect White Cake', '	\r\nIngredients\r\n2 1/4 cups cake flour \r\n1 cup milk at room temperature \r\n6 large egg whites at room temperature \r\n2 teaspoons almond extract \r\n1 teaspoon vanilla extract \r\n1 3/4 cups granulated sugar \r\n4 teaspoons baking powder \r\n1 teaspoon table salt \r\n1 1/2 sticks unsalted butter, softened but still cool \r\n', 'Instructions\r\n1. Heat oven to 350 degrees. Prepare two 8-inch cake pans. \r\n2. Make sure milk and eggs are room temperature. \r\n3. Pour milk (I used skim) , egg whites, and extracts into medium bowl and mix with fork until blended. \r\n4. Mix cake flour, sugar, baking powder, and salt in bowl of electric mixer at slow speed. Add butter, cut into cubes and continue beating on low for about 1-2 minutes. \r\n5. Add all but 1/2 cup of milk mixture to flour mixture and beat at medium speed for 1 1/2 minutes. Add remaining 1/2 cup of milk mixture and beat for about 1 minute. \r\n6. Pour batter evenly between two prepared cake pans. \r\n7. Bake until toothpick inserted in the center comes out clean, 27 to 30 minutes. \r\n8. Allow cake to cool to room temperature. \r\n9. Frost cakes with favorite frosting. \r\n10. Just barely adapted from epicurious, taken from Cooks Illustrated.\r\nSchema/Recipe SEO Data Markup by ZipList Recipe Plugin\r\n', 'The Perfect White Cake\r\ngood\r\n', 45),
(627, 'butter pecan cookies', 'EVEL: EASY\r\nINGREDIENTS\r\n\r\nFOR THE COOKIES\r\n1 c. butter, softened\r\n1 c. brown sugar\r\n1/2 c. sugar\r\n2 tsp. vanilla\r\n2 large eggs\r\n2 c. all-purpose flour\r\n1/2 tsp. baking soda\r\n1/4 tsple syrup\r\n2 tbsp. heavy cream\r\nChopped pecans, for decorating\r\np. kosher salt\r\n1 c. chopped pecans\r\nFOR THE MAPLE BUTTERCREAM\r\n1 stick butter\r\n1 1/2 c. powdered sugar\r\n2 tbsp. maple syrup\r\n2 tbsp. heavy cream\r\nChopped pecans, for decorating', '\r\n\r\nPreheat oven to 350º. In a large bowl using a hand mixer, beat butter and sugars until smooth. Add vanilla and eggs and beat until combined, then add flour, baking soda, and salt and mix until just combined. Fold in chopped pecans.\r\nSpoon tablespoon scoops onto a parchment-lined baking sheet and bake until golden, 10 to 12 minutes. Let cool.\r\nMake frosting: In a large bowl using a hand mixer, beat butter until smooth and fluffy, 2 minutes. Add powdered sugar and beat until combined, then add maple syrup and heavy cream and beat until creamy. (If the frosting is too thin, beat in more powdered sugar, 1/4 cup at a time.)\r\nFrost cookies anddecorate with more pecans.\r\nPIN IT FOR LATER:', 'new', 58);

-- --------------------------------------------------------

--
-- Stand-in structure for view `recipe_view`
-- (See below for the actual view)
--
CREATE TABLE `recipe_view` (
`cat_title` varchar(200)
,`recipe_note` text
,`recipe_method` text
,`recipe_ingredients` text
,`recipe_title` varchar(200)
,`recipeID` int(11)
,`catID` int(11)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `catrecipe`
--
ALTER TABLE `catrecipe`
  ADD PRIMARY KEY (`catID`);

--
-- Indexes for table `login_users`
--
ALTER TABLE `login_users`
  ADD PRIMARY KEY (`User_ID`);

--
-- Indexes for table `menu`
--
ALTER TABLE `menu`
  ADD PRIMARY KEY (`Menu_ID`);

--
-- Indexes for table `recipe`
--
ALTER TABLE `recipe`
  ADD PRIMARY KEY (`recipeID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `catrecipe`
--
ALTER TABLE `catrecipe`
  MODIFY `catID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `login_users`
--
ALTER TABLE `login_users`
  MODIFY `User_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `menu`
--
ALTER TABLE `menu`
  MODIFY `Menu_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `recipe`
--
ALTER TABLE `recipe`
  MODIFY `recipeID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=629;

-- --------------------------------------------------------

--
-- Structure for view `recipe_view`
--
DROP TABLE IF EXISTS `recipe_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`beatrice`@`%` SQL SECURITY DEFINER VIEW `recipe_view`  AS SELECT `catrecipe`.`cat_title` AS `cat_title`, `recipe`.`recipe_note` AS `recipe_note`, `recipe`.`recipe_method` AS `recipe_method`, `recipe`.`recipe_ingredients` AS `recipe_ingredients`, `recipe`.`recipe_title` AS `recipe_title`, `recipe`.`recipeID` AS `recipeID`, `recipe`.`catID` AS `catID` FROM (`recipe` left join `catrecipe` on(`recipe`.`catID` = `catrecipe`.`catID`)) ORDER BY `catrecipe`.`cat_title` ASC ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
