<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ModLog extends Model
{
	protected $visible = ['id'];

	protected $fillable = ['*'];

	public function admin()
	{
		return $this->belongsTo(User::class, 'user_id');
	}

	public function actionToText()
	{
		$msg = 'Unknown action';

		switch ($this->action) {
			case 'admin.user.mail':
				$msg = "Sent Message";
				break;

			case 'admin.user.action.cw.warn':
				$msg = "Sent CW reminder";
				break;

			case 'admin.user.edit':
				$msg = "Changed Profile";
				break;

			case 'admin.user.moderate':
				$msg = "Moderation";
				break;

			case 'admin.user.delete':
				$msg = "Deleted Account";
				break;

            case 'system.user.desactive':
                $msg = "[System] Deactivated Account";
                break;

            case 'system.user.unpopular':
                $msg = "[System] Removed from Popular Users";
                break;
                
            case 'system.user.popular':
                $msg = "[System] Added to Popular Users";
                break;

			default:
				$msg = 'Unknown action';
				break;
		}

		return $msg;
	}
}
