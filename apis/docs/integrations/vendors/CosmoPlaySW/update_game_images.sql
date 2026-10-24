UPDATE `games`
SET `image` = CONCAT(
    'https://example.invalid/cdn-betmonsters-com-cdn-bucket/games/CosmoPlay/games/',
    REPLACE(`name`, ' ', '%20'),
    '.webp'
)
WHERE `vendor_id` = 249;
