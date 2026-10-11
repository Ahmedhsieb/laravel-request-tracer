# Images

The documentation refers to a few screenshots that are not committed yet. Capture them from the demo dashboard (`npm run dev`, then open `http://localhost:5173/`, order trace `POST /orders`) at 1440 × 900 and save them here with these names:

| File | What to show |
|---|---|
| `dashboard-flow.png` | A trace open on **Flow**: the metric strip, the stacked time bar, the step tree with `OrderService::create` pinned and its Input / Output on the right. |
| `dashboard-code.png` | **Inside the code** for `OrderService::create`: green, purple and grey dots, the `false` chip on the VIP condition, the loop with `6 iterations`, and the line card open. |
| `dashboard-database.png` | The **Database** tab: the `orders` insert, `order_items` and the `products` update with before → after cells. |
| `dashboard-problems.png` | The **Problems** tab with the N+1, the failed HTTP call, the caught exception and the slow step. |
| `replay.gif` | Pressing **Replay** on the code view (about 8 seconds). |

Keep each PNG under 300 KB (`pngquant` is fine) and add the alt text in the page that embeds it.
