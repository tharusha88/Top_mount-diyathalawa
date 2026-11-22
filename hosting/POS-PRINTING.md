# Printing on the POS computer (Linux) without the print window

Bills, payment invoices and receipts print on **A5** (Admin → Settings → Printing). Each page
prints the **date and time** at the top left and **who issued it** at the top right. The browser's
own header and footer (page title, web address, page numbers) are switched off by the site itself.

Normally the browser shows a print window first. On the POS computer you can skip it: Chrome has a
**kiosk printing** mode that sends every print straight to the default printer. This works the same
on the live site (https://www.yourdomain.lk/pos) as on a local copy, because the printing happens in
the POS computer's browser, not on the server.

## 1. Connect the printer (Pantum P2500)

1. Connect the printer by USB and switch it on.
2. Install the Linux driver: on **pantum.com** go to **Support → Download**, find your model
   (P2500 series), choose **Linux**, download the `.deb` file and open it to install. (Many Linux
   versions find the printer by themselves; install the driver anyway if prints come out wrong.)
3. Open **Settings → Printers**, add the Pantum if it isn't listed, and **make it the default printer**.
4. In the printer's options, set the paper size to **A5**.
5. Load A5 paper in the tray and slide the paper guides in to fit it.
6. Print a test page from Settings → Printers to check it works.

You can also set the default printer and paper in a terminal. Use the printer name that
`lpstat -p` shows:

```bash
lpstat -p
lpoptions -d Pantum_P2500_series
lpoptions -p Pantum_P2500_series -o media=A5
```

## 2. Install Google Chrome

Download the `.deb` from https://www.google.com/chrome/ and open it to install. Chromium works too.
(Firefox can't hide its own header and footer the same way, so use Chrome.)

## 3. Make the "Top Mountain POS" shortcut

On the POS computer, open **Admin → Settings → Printing** and download the **Linux** shortcut
(`topmountain-pos-linux.sh`, also in `public/downloads/`), then open a terminal in
that folder and run:

```bash
bash topmountain-pos-linux.sh
```

It makes a **Top Mountain POS** shortcut on the desktop and in the app menu, and opens it when the
computer starts. The shortcut starts Chrome with kiosk printing, in its own window without an
address bar, at https://www.yourdomain.lk/pos.

To use another address, put it after the script name, for example:
`bash topmountain-pos-linux.sh http://192.168.1.20:3000/pos`.

## Windows computer

The same works on Windows with the **Windows** shortcut (Admin → Settings → Printing →
**Windows**, i.e. `topmountain-pos-windows.bat`):

1. Make the Pantum the **default printer** (Settings → Bluetooth & devices → Printers & scanners →
   untick "Let Windows manage my default printer", choose the Pantum → **Set as default**), and set
   its paper to **A5** in its **Printing preferences**.
2. Download it on the POS computer and double-click it (if Windows asks, choose **More info → Run
   anyway**). It shows which printer the prints go to, and warns if that isn't a real printer.
   Close any other POS window first. Put a shortcut to it on the desktop for every day.
3. Sign in and press **Print**: it prints straight away, with no print window.

Printing from a normal browser window, or from someone's phone, always shows the print window first —
only the window the shortcut opens skips it.

## 4. Use it

1. Open **Top Mountain POS** and sign in with the POS login (Admin → Staff logins → a "Front desk"
   login). The sign-in lasts 12 hours.
2. Press **Print** on a bill, receipt or invoice. It prints straight away. No window opens.

## If something is wrong

| Problem | Fix |
| --- | --- |
| The print window still opens | Close every POS window and open it again from the **Top Mountain POS** shortcut (Linux) or `topmountain-pos-windows.bat` (Windows). Kiosk printing only works in a window that shortcut started, never in a normal browser window. |
| It prints on the wrong printer | Make the Pantum the default printer (step 1.3), then reopen the POS. |
| It prints on A4, or the page is cut off | Set A5 in the printer's options (step 1.4) and in **Admin → Settings → Printing**. |
| Nothing prints | Check the printer is on, has paper and shows no error. Print a test page from Settings → Printers. |
| You need the print window again (e.g. to save a PDF) | Use a normal Chrome window, not the POS shortcut. |
