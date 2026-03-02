[root@srv1078162 divi-recipe-child]# ls -la /var/www/vhosts/godin.com/recipes.godin.com/*.php | grep import
// CLI-only script protection
if (!isset($argc) || php_sapi_name() !== 'cli') {
    return;
}
[root@srv1078162 divi-recipe-child]# ls -la /var/www/vhosts/godin.com/recipes.godin.com/wp-content/themes/divi-recipe-child/*.php | grep import
-rw-r--r-- 1 root                  root   13641 Feb 10 22:06 /var/www/vhosts/godin.com/recipes.godin.com/wp-content/themes/divi-recipe-child/import-recipes-v3.php
-rw-r--r-- 1 godin.com_mn3mxiulc0n psacln 17240 Feb 10 14:48 /var/www/vhosts/godin.com/recipes.godin.com/wp-content/themes/divi-recipe-child/import-recipes-v3_pre relation.php
[root@srv1078162 divi-recipe-child]# 