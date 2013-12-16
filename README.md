## Magento module to list rendered blocks tree and SQL queries

![Rendered blocks](http://i.imgur.com/6spPPyQ.png)

![SQL queries](http://i.imgur.com/yWswA7c.png)

### Installation instructions

Install with [modgit](https://github.com/jreinke/modgit):

    $ cd /path/to/magento
    $ modgit init
    $ modgit clone debug https://github.com/jreinke/magento-debug.git

or download package manually [here](https://github.com/jreinke/magento-debug/archive/master.zip) and unzip in Magento root folder.

Finally, clear cache.

### .gitignore (optional)

Is is recommended to ignore this module files. Add this to your .gitignore file:

    app/code/community/Bubble/Debug/*
    app/code/local/Zend/Db/Adapter/Pdo/Abstract.php
    app/etc/modules/Bubble_Debug.xml

### Usage

##### Enable debugging for current page

shop.example.com/apparel.html`?debug=1`

##### Enable debugging permanently

shop.example.com/apparel.html`?debug=perm`

##### Disable permament debugging

shop.example.com/apparel.html`?debug=0`
