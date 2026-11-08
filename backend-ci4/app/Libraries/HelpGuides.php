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
                'title' => 'Reset a staff password yourself',
                'steps' => [
                    'Go to Settings, then open the Manage Accounts tab.',
                    'Click Reset Password on the staff member\'s row.',
                    'Type the new password twice, then your own password.',
                    'Tick "I have informed (their name) about this reset", then click Reset.',
                    'Tell the staff member the new password yourself. They get an email that it was reset, but it does not contain the password.',
                    'When they sign in with it, they must create their own password. If the account was locked, it is unlocked.',
                ],
            ],
            [
                'title' => 'Confirm or lock after a staff password reset',
                'steps' => [
                    'When a staff member uses "Forgot password?" and sets a new password, you get an email and a notice in Notifications.',
                    'Ask yourself: did you know about it? If it was really them, click Accept.',
                    'If it was not, click Lock this account. They are signed out on their next click.',
                    'Only one of the two works. Once you choose, the other button stops working.',
                    'To unlock a locked account, use Reset Password in Manage Accounts.',
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
                    'Open Reorder Alerts or Inventory and click Order on an item, or open Orders and click Add New Order.',
                    'On Orders, choose the supplier first. Then click + Add item and choose their items.',
                    'Check the quantity and the unit cost of each item.',
                    'An order with an item out of stock is Urgent. On Orders you can change it to Normal or Planned.',
                    'Check the expected delivery date, then click Create order. The supplier is told about the order.',
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
                    'Type your username or full name, then click Send temporary password.',
                    'Check your email for the temporary password. It works for 2 hours, and your old password keeps working until you use it.',
                    'Sign in with the temporary password. You\'ll be asked to create your own new password.',
                    'The owner is told that you reset it. If you have no email on file, ask the owner to reset it from Manage Accounts.',
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
