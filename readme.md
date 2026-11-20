# WooCommerce LINE Pay Gateway for Taiwan

源自 LINE Pay 1.1.0 外掛的社群維護版，提供台灣 WooCommerce 商店的 LINE Pay 付款整合。

## 1.1.1｜2026-11-21

付款請求成功後，外掛保留原本的結帳回跳，並使用 Action Scheduler 安排背景查詢。排程以 LINE Pay 建立交易的時間為基準，約在 **30 秒、90 秒、3 分鐘、6 分鐘、10 分鐘、15 分鐘及 20 分鐘**查詢。實際執行時間取決於商店排程的運作情況。

- 顧客仍在 LINE Pay 認證時，等待下一次查詢。顧客取消、認證逾時或付款失敗時，停止自動查詢。
- LINE Pay 回傳 `0110` 時，表示顧客已完成認證。訂單仍可付款且交易資料吻合時，外掛使用原交易執行 Confirm，沿用 LINE Pay 台灣的自動請款模式。
- 若 Confirm 的結果不明，或 LINE Pay 顯示付款已完成，外掛查詢付款明細。確認已請款，且交易 ID、訂單號、幣別和金額吻合後，才在 WooCommerce 補記付款。
- 付款回跳與背景查詢共用訂單鎖。已付款訂單保留現有狀態，避免再次觸發付款完成流程。

[LINE Pay 付款請求狀態](https://developers-pay.line.me/zh/online-api-v3/check-payment-request-status) · [付款授權](https://developers-pay.line.me/zh/online-api-v3/confirm-payment) · [付款明細](https://developers-pay.line.me/zh/online-api-v3/retrieve-payment-details)

## 既有訂單補查

在 WooCommerce 後台開啟 LINE Pay 訂單，從「訂單操作」選擇「LINE Pay 付款對帳」。這個動作向 LINE Pay 查詢交易狀態與明細，並在核對到符合條件的已請款交易後補記 WooCommerce 付款。如果原訂單已有付款日期，外掛會留下核對結果供人工確認。

## 安裝與驗證

1. 在已啟用 WooCommerce 的 WordPress 商店安裝並啟用外掛。升級時沿用原外掛目錄。
2. 在 WooCommerce → 設定 → 付款啟用 LINE Pay，並設定對應環境的 Channel ID 和 Channel Secret Key。需要分店標記時，可填寫選填的分店名稱。
3. 在 WooCommerce → 狀態 → 排定的動作檢查 Action Scheduler。商店需能執行排程。若停用 WP-Cron，請確認主機已有替代的排程機制。 [WooCommerce 排程說明](https://woocommerce.com/document/understanding-the-woocommerce-system-status-report/scheduled-actions/)
4. 建議先在測試站開啟 Sandbox Mode，使用 LINE Pay Sandbox 驗證正常回跳、回跳未抵達時的背景補查，以及取消付款的情況。可從 WooCommerce 訂單備註和排定的動作確認結果。 [LINE Pay Sandbox 設定](https://developers-pay.line.me/zh/online/prerequisites)

## 授權

原始外掛著作權 © 2015 LINE Pay。此版本沿用 GNU General Public License v3.0 或任何後續版本（GPL-3.0-or-later）。[完整授權條款](LICENSE)
