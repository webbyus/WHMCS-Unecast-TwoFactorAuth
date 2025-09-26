# Unecast WHMCS 2FA Module

This module provides Two-Factor Authentication (2FA) for WHMCS administrators using **SMS and Email** via Unecast.

---

## Features
- Send OTP to admin’s registered mobile number
- Supports both **Email** and **SMS** delivery methods
- Mobile number stored in WHMCS `tbladmins.authdata` as JSON
- Fully compatible with WHMCS 8.x+

---

## Database Storage

The admin mobile number is stored inside `tbladmins.authdata` JSON field:

```json
{
  "admin_mobile": "94718761292",
  "backupcode": "67be8c..."
}
