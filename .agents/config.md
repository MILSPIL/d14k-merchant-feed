# GMC Feed Config

- URL продакшн: Кілька (14karat.biz.ua, techmashagro.com.ua, filler.com.ua)
- SSH: `14karat`, `techmashagro`, `filler`
- Шлях до WP root: Різні (наприклад `/home/diamond2/public_html`)
- Тема: WoodMart (найчастіше)

**Специфіка деплою:**

```bash
rsync -avz --progress --exclude='.git' --exclude='.DS_Store' -e "ssh -i ~/.ssh/chemicloud-diamonds14k -p 1988" /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/ diamond2@rs7-fra.serverhostgroup.com:/home/diamond2/public_html/wp-content/plugins/gmc-feed-for-woocommerce/ 
ssh 14karat "wp --path=/home/diamond2/public_html cache flush"
```
