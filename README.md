# Chargebee Fetch Data

## Description

This Drupal 9 or 10 module, **Chargebee Fetch Data**, provides a mechanism to synchronize subscription data from Chargebee with user profiles in your Drupal site. It's designed to run as a batch process that updates user fields based on active Chargebee subscriptions. This is particularly useful for membership sites where user roles and access are tied to subscription statuses.

The module introduces an administrative interface where you can trigger the data fetch and update process. It's built to be robust, handling large numbers of users by processing them in manageable chunks and includes a retry mechanism for API rate limiting.

---

## Data Structure and Workflow

The core of this module's functionality lies in its ability to connect a Drupal user to their Chargebee subscription and pull in relevant data. Here's how it works:

1.  **User Identification**: The process starts by identifying Drupal users who have a "member" role and a value in the `field_user_chargebee_id` field. This Chargebee customer ID is the key to linking a Drupal user to their Chargebee data.

2.  **API Communication**: Using the Chargebee API, the module fetches the active subscription for each identified user. The API key and portal URL for this communication are configured in the **Chargebee Portal** settings (`/admin/config/services/chargebee-portal`).

3.  **Data Extraction**: From the subscription data, the module extracts two key pieces of information:
    * **Plan ID**: The unique identifier for the user's subscription plan.
    * **Plan Amount**: The cost of the plan, which is provided in cents.

4.  **User Field Updates**: The extracted data is then used to update fields on the user's profile:
    * `field_user_chargebee_plan`: This field is populated with the **Plan ID**.
    * `field_member_payment_monthly`: This field is updated with the **Plan Amount**, which the module converts from cents to a standard currency format. If this field doesn't exist on the user entity, the module will attempt to update it on the user's "main" profile.

The entire process is managed through a batch operation to prevent timeouts and server overload. You can process all eligible users at once or specify a single User ID (UID) for testing. There are also options to start the process from a specific UID and to add a delay between processing each user.

---

## Configuration

To use this module, follow these steps:

1.  **Dependencies**: Ensure that the **Chargebee Portal** and **User** modules are enabled in your Drupal installation.

2.  **API Credentials**: Navigate to the Chargebee Portal settings page at `/admin/config/services/chargebee-portal` and enter your live API key and portal URL.

3.  **User Fields**: Make sure your user entity has the following fields:
    * `field_user_chargebee_id` (Text field to store the Chargebee Customer ID)
    * `field_user_chargebee_plan` (Text field to store the Chargebee Plan ID)
    * `field_member_payment_monthly` (Numeric field for the monthly payment amount)

4.  **Execution**: Go to the **Chargebee Fetch Data** administration page at `/admin/config/services/chargebee-fetch-data`. From here you can:
    * Run the process for all members.
    * Test with a single UID.
    * Set a starting UID for a partial run.
    * Introduce a delay between user processing.
    * **Enable revisions** to create a history of changes for each update.
    * Enable detailed logging for troubleshooting.

Click the "Fetch and Update Data" button to start the process. The module will provide status messages indicating the number of users found and the progress of the batch operation.

---

## Billing Plan Vocabulary Integration

Each time the batch runs it now also synchronizes the `billing_plan` vocabulary:

- If a taxonomy term for the Chargebee plan does not exist it will be created automatically with the plan ID as its machine value.
- The plan term stores the monthly amount, currency, and which billing system owns the plan (`chargebee`, `stripe`, etc.).
- When the term already has an associated membership type selected in `field_membership_type`, the sync assigns that membership to the member's profile automatically.

Edit the plan term to connect it to the appropriate membership type once per plan; future syncs keep the taxonomy information current.

---

## Running from Drush / Cron

You can trigger the same synchronization without the UI via the new Drush command:

```bash
drush chargebee:sync-plans --start-uid=1000 --delay=1
```

Options mirror the form inputs (`--uid`, `--start-uid`, `--delay`, `--create-revision`, `--detailed`). This enables cron-based refreshes or manual CLI invocations whenever you want to reconcile Chargebee with Drupal.
