const https = require('https');
const fs = require('fs');

const url = "https://filler.com.ua/wp-content/uploads/d14k-feeds/yml-horoshop.xml";

https.get(url, (res) => {
    let rawData = '';
    res.on('data', (chunk) => { rawData += chunk; });
    res.on('end', () => {
        try {
            const startTag = '<categories>';
            const endTag = '</categories>';
            const startIndex = rawData.indexOf(startTag);
            const endIndex = rawData.indexOf(endTag) + endTag.length;

            if (startIndex !== -1 && endIndex !== -1) {
                const categoriesBlock = rawData.substring(startIndex, endIndex);
                const date = new Date().toISOString().slice(0, 16).replace('T', ' ');

                const output = `<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="${date}">
<shop>
  <name>FILLER.COM.UA</name>
  <company>FILLER.COM.UA</company>
  <url>https://filler.com.ua</url>
  <currencies>
    <currency id="UAH" rate="1"/>
  </currencies>
${categoriesBlock}
  <offers>
  </offers>
</shop>
</yml_catalog>`;

                fs.writeFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/yml-horoshop-categories-only.xml', output);
                console.log("Successfully extracted categories to /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/yml-horoshop-categories-only.xml");
            } else {
                console.error("Could not find categories block in XML.");
            }
        } catch (e) {
            console.error(e.message);
        }
    });
}).on('error', (e) => {
    console.error(`Got error: ${e.message}`);
});
