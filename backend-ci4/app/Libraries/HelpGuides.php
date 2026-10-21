<?php

namespace App\Libraries;

/**
 * The short task guides on each role's Help page: plain words, 3-6 numbered
 * steps, one action per step. Text only; nothing here changes how the app works.
 */
class HelpGuides
{
    /** @return list<array{title: string, steps: list<string>}> */
    public static function for(string $role): array
    {
        return match ($role) {
            'owner'    => self::owner(),
            'staff'    => self::staff(),
            'supplier' => self::supplier(),
            default    => [],
        };
    }

    private static function owner(): array
    {
        return [
            [
                'title' => 'Approve a staff password reset',
                'steps' => [
                    'Open the email "Password reset requested by…" (or click Notifications in the menu).',
                    'Check with the staff member, by call or chat, that they really sent the request.',
                    'Click Approve reset, tick "I confirmed with them", then click Approve reset again.',
                    'The system emails a temporary password directly to the staff member. You won\'t see it.',
                    'They sign in with it and set their own new password. You\'ll get an email when they\'re done.',
                ],
            ],
            [
                'title' => 'Reset a staff password yourself',
                'steps' => [
                    'Go to Settings, then open the Manage Accounts tab.',
                    'Click Reset Password on the staff member\'s row.',
                    'Enter your own password and tick "I confirmed with them".',
                    'Click Reset. A temporary password is emailed to the staff member. (If they have no email, it\'s shown to you once. Give it to them privately.)',
                ],
            ],
            [
                'title' => 'Add a staff account',
                'steps' => [
                    'Go to Settings, then open the Manage Accounts tab.',
                    'Click Add Staff Account.',
                    'Type their name, a username and their email.',
                    'Click Save & create account.',
                    'A temporary password is emailed to them. It works for 48 hours. They set their own when they sign in.',
                ],
            ],
            [
                'title' => 'Run the DSS engine',
                'steps' => [
                    'Go to Settings, then open the DSS Parameter tab.',
                    'Click Recalculate now.',
                    'Keep working. It runs in the background.',
                    'Wait for the notice that says it is done.',
                    'Open Reorder Alerts or Inventory to see the new numbers.',
                ],
            ],
            [
                'title' => 'Change the DSS settings',
                'steps' => [
                    'Go to Settings, then open the DSS Parameter tab.',
                    'Change the numbers you need. Click the (i) next to a name if you are not sure what it means.',
                    'Click Save.',
                    'Click Recalculate now so the new settings are used.',
                ],
            ],
            [
                'title' => 'Read the ABC classification',
                'steps' => [
                    'Open Trend Analysis in the menu.',
                    'Find the ABC classification card.',
                    'A items are your best sellers. Never let them run out.',
                    'B items sell steadily. C items sell slowly, so order fewer.',
                    'Click All, A, B or C above the list to see one group.',
                    'The number on the left is the rank. 1 sells the most.',
                ],
            ],
            [
                'title' => 'Create a stock order',
                'steps' => [
                    'Open Reorder Alerts and click Order on an item, or open Orders and click Add New Order.',
                    'Choose the item and the supplier.',
                    'Type the quantity and the price per item.',
                    'Choose Urgent or Planned, then pick the expected delivery date.',
                    'Click Submit. The supplier is told about the order.',
                ],
            ],
        ];
    }

    private static function staff(): array
    {
        return [
            [
                'title' => 'Record a sale',
                'steps' => [
                    'Open POS & Sales in the menu.',
                    'Click each item the customer is buying. Pick the size if asked.',
                    'Check the items and quantities in Current order.',
                    'Choose Cash, GCash or Cash+GCash.',
                    'Type the amount received. For GCash, type the reference number.',
                    'Click Checkout & Print Receipt.',
                ],
            ],
            [
                'title' => 'Log a delivery',
                'steps' => [
                    'Open Log Transaction in the menu.',
                    'Click the Restock card (supplier delivery arrived).',
                    'Find the delivery that arrived.',
                    'Tick each item you have received.',
                    'Click Confirm Delivery Received. The owner is told.',
                ],
            ],
            [
                'title' => 'Change my password',
                'steps' => [
                    'Click your name at the bottom of the menu.',
                    'Under Change Password, type your current password, then your new one twice.',
                    'Click Change Password. The owner is notified that you changed it.',
                ],
            ],
            [
                'title' => 'I forgot my password',
                'steps' => [
                    'On the staff sign-in page, click "Forgot password?".',
                    'Type your name and the reason, then click Send.',
                    'Wait for an email with a temporary password (it works for 2 hours).',
                    'Sign in with it. You\'ll be asked to set your own new password.',
                ],
            ],
        ];
    }

    private static function supplier(): array
    {
        return [
            [
                'title' => 'Accept a new order',
                'steps' => [
                    'Open New Orders in the menu.',
                    'Look at the items, quantities and prices.',
                    'Click Accept Order if you can deliver.',
                    'Click Decline if you cannot. Type the reason when asked.',
                ],
            ],
            [
                'title' => 'Mark an order as shipped',
                'steps' => [
                    'Open Deliveries in the menu.',
                    'Find the order you are sending.',
                    'Check each item and quantity you are shipping.',
                    'Type the tracking number if you have one.',
                    'Click Confirm & Ship.',
                ],
            ],
            [
                'title' => 'Update my profile',
                'steps' => [
                    'Click your name at the bottom of the menu.',
                    'Click Edit on the contact details.',
                    'Change what you need.',
                    'Click Save.',
                ],
            ],
        ];
    }
}
