<?php
/**
 * Scrolling News Ticker Header
 * Add this to your child theme header or as a shortcode
 */

// Only show if user is logged in
if (!is_user_logged_in()) {
    return;
}
?>

<style>
/* Hide empty Divi header that causes white space */
.et-l--header {
    display: none !important;
}

/* Aggressively remove all space above ticker */
#et-main-area {
    padding-top: 0 !important;
}

#main-content {
    padding-top: 0 !important;
}

#page-container {
    padding-top: 0 !important;
}

.et_pb_section {
    padding-top: 0 !important;
}

/* Remove any Divi container padding */
body:not(.home) #main-content .container:first-child {
    padding-top: 0 !important;
}

/* Make ticker stick right after admin bar */
body.admin-bar .recipe-ticker-wrapper {
    position: fixed;
    top: 32px; /* Admin bar height */
    left: 0;
    right: 0;
    z-index: 999;
    margin: 0 auto;
}

/* Push content down to account for fixed ticker */
body.admin-bar #page-container {
    padding-top: 95px !important; /* Admin bar (32px) + ticker (~48px) + spacing (15px) */
}

@media screen and (max-width: 782px) {
    body.admin-bar .recipe-ticker-wrapper {
        top: 46px; /* Mobile admin bar height */
    }
    
    body.admin-bar #page-container {
        padding-top: 109px !important; /* Mobile admin bar (46px) + ticker (~48px) + spacing (15px) */
    }
}

/* Non-logged in users */
body:not(.admin-bar) .recipe-ticker-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 999;
    margin: 0 auto;
}

body:not(.admin-bar) #page-container {
    padding-top: 63px !important; /* Just ticker height + spacing (15px) */
}

.recipe-ticker-wrapper {
    background: linear-gradient(135deg, #c84a31 0%, #a63820 100%);
    color: white;
    padding: 8px 0;
    overflow: hidden;
    position: relative;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 0;
    max-width: 1400px; /* Match content width */
    margin-left: auto;
    margin-right: auto;
    border-radius: 4px; /* Soften edges */
}

/* Reduce space in main content area after ticker */
.recipe-ticker-wrapper ~ * {
    margin-top: 0 !important;
}

.recipe-ticker-container {
    display: flex;
    align-items: center;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
}

.ticker-label {
    font-weight: bold;
    font-size: 18px;
    margin-right: 20px;
    white-space: nowrap;
    font-family: 'Brush Script MT', cursive, Arial, sans-serif;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
    line-height: 1.2;
}

.ticker-label a {
    transition: color 0.2s ease;
}

.ticker-label a:hover {
    color: #2980b9 !important;
    text-decoration: underline !important;
}

.ticker-content {
    flex: 1;
    overflow: hidden;
    position: relative;
}

.ticker-tape {
    display: flex;
    animation: scroll-left 60s linear infinite;
    will-change: transform;
}

.ticker-tape:hover {
    animation-play-state: paused;
}

.ticker-item {
    white-space: nowrap;
    padding: 0 40px;
    font-size: 14px;
}

.ticker-item a {
    color: white;
    text-decoration: none;
    transition: opacity 0.3s;
}

.ticker-item a:hover {
    opacity: 0.8;
    text-decoration: underline;
}

.ticker-separator {
    margin: 0 10px;
    opacity: 0.5;
}

@keyframes scroll-left {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(-50%);
    }
}

@media (max-width: 768px) {
    .ticker-label {
        font-size: 16px;
        margin-right: 10px;
    }
    
    .ticker-item {
        font-size: 13px;
        padding: 0 20px;
    }
}
</style>

<div class="recipe-ticker-wrapper">
    <div class="recipe-ticker-container">
        <div class="ticker-label">
            Personal Recipes
            <div style="font-size: 16px; margin-top: 4px; font-weight: 600;">
                <a href="<?php echo home_url('/recipe-manager/'); ?>" style="color: #FFD700; text-decoration: none;">
                    ← Home
                </a>
            </div>
        </div>
        <div class="ticker-content">
            <div class="ticker-tape">
                <!-- First set of items -->
                <div class="ticker-item">
                    <a href="https://www.foodnetwork.com/" target="_blank" rel="noopener">🍳 Food Network: Latest Recipes & Cooking Tips</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.bonappetit.com/" target="_blank" rel="noopener">👨‍🍳 Bon Appétit: Chef-Approved Techniques</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.seriouseats.com/" target="_blank" rel="noopener">🔬 Serious Eats: Science of Good Food</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.epicurious.com/" target="_blank" rel="noopener">📖 Epicurious: Thousands of Tested Recipes</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.kingarthurbaking.com/" target="_blank" rel="noopener">🥖 King Arthur Baking: Perfect Your Baking Skills</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.allrecipes.com/" target="_blank" rel="noopener">⭐ AllRecipes: Community Favorites & Reviews</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.thekitchn.com/" target="_blank" rel="noopener">🏠 The Kitchn: Home Cooking Inspiration</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.tastingtable.com/" target="_blank" rel="noopener">🍷 Tasting Table: Food Culture & Trends</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <!-- Duplicate set for seamless loop -->
                <div class="ticker-item">
                    <a href="https://www.foodnetwork.com/" target="_blank" rel="noopener">🍳 Food Network: Latest Recipes & Cooking Tips</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.bonappetit.com/" target="_blank" rel="noopener">👨‍🍳 Bon Appétit: Chef-Approved Techniques</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.seriouseats.com/" target="_blank" rel="noopener">🔬 Serious Eats: Science of Good Food</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.epicurious.com/" target="_blank" rel="noopener">📖 Epicurious: Thousands of Tested Recipes</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.kingarthurbaking.com/" target="_blank" rel="noopener">🥖 King Arthur Baking: Perfect Your Baking Skills</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.allrecipes.com/" target="_blank" rel="noopener">⭐ AllRecipes: Community Favorites & Reviews</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.thekitchn.com/" target="_blank" rel="noopener">🏠 The Kitchn: Home Cooking Inspiration</a>
                </div>
                <span class="ticker-separator">•</span>
                
                <div class="ticker-item">
                    <a href="https://www.tastingtable.com/" target="_blank" rel="noopener">🍷 Tasting Table: Food Culture & Trends</a>
                </div>
                <span class="ticker-separator">•</span>
            </div>
        </div>
    </div>
</div>