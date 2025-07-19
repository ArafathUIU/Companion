import pandas as pd
import numpy as np
import mysql.connector
import sys
import logging
from datetime import datetime
import json
import os

# Set up logging
log_file = os.getenv('MOOD_LOG_FILE', 'predict_mood.log')
logging.basicConfig(filename=log_file, level=logging.DEBUG,
                    format='%(asctime)s - %(levelname)s - %(message)s')

def get_db_connection():
    try:
        conn = mysql.connector.connect(
            host="127.0.0.1",
            user="root",
            password="",
            database="companionx"
        )
        logging.info("Database connection successful")
        return conn
    except mysql.connector.Error as e:
        logging.error(f"Database connection failed: {e}")
        raise

def fetch_mood_entries(conn, user_id):
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT mood, intensity, notes, created_at
            FROM user_mood_entries
            WHERE user_id = %s
            ORDER BY created_at DESC
        """, (user_id,))
        entries = cursor.fetchall()
        logging.info(f"Fetched {len(entries)} mood entries for user_id {user_id}")
        return entries
    except Exception as e:
        logging.error(f"Error fetching mood entries: {e}")
        return []
    finally:
        cursor.close()

def fetch_mood_tasks(conn, mood):
    valid_moods = {'Happy', 'Sad', 'Neutral', 'Angry', 'Excited'}
    if mood not in valid_moods:
        mood = 'Neutral'
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT task_type, description, icon
            FROM mood_tasks
            WHERE mood = %s
            ORDER BY RAND()
            LIMIT 2
        """, (mood,))
        tasks = cursor.fetchall()
        # Ensure valid Font Awesome icons
        for task in tasks:
            task['icon'] = task['icon'] or 'fas fa-lightbulb'
        logging.info(f"Fetched {len(tasks)} tasks for mood {mood}")
        return tasks
    except Exception as e:
        logging.error(f"Error fetching mood tasks: {e}")
        return []
    finally:
        cursor.close()

def predict_mood(user_id):
    try:
        if user_id <= 0:
            raise ValueError("user_id must be a positive integer")
        
        conn = get_db_connection()
        entries = fetch_mood_entries(conn, user_id)
        
        if not entries:
            logging.warning(f"No mood entries for user_id {user_id}")
            return {
                'success': True,
                'mood': 'Neutral',
                'stability': 50,
                'message': 'Please log some moods to get personalized predictions.',
                'tasks': fetch_mood_tasks(conn, 'Neutral')
            }

        # Mood mapping to numerical values (aligned with PHP)
        mood_map = {'Happy': 4, 'Excited': 3, 'Neutral': 2, 'Sad': 1, 'Angry': 0}
        mood_scores = [(mood_map.get(entry['mood'], 2), entry['intensity'], entry['created_at']) for entry in entries]

        if len(entries) < 14:
            # First 14 entries: Use recent 3 entries for simple analysis
            recent_entries = entries[:3]
            if not recent_entries:
                return {
                    'success': True,
                    'mood': 'Neutral',
                    'stability': 50,
                    'message': 'Log more moods to see trends!',
                    'tasks': fetch_mood_tasks(conn, 'Neutral')
                }
            avg_intensity = np.mean([entry['intensity'] for entry in recent_entries])
            dominant_mood = max(set([entry['mood'] for entry in recent_entries]), key=[entry['mood'] for entry in recent_entries].count)
            tasks = fetch_mood_tasks(conn, dominant_mood)
            return {
                'success': True,
                'mood': dominant_mood,
                'stability': min(max(round(avg_intensity * 10), 0), 100),
                'message': f"Your recent mood is mostly {dominant_mood.lower()}. {len(entries)}/14 entries logged.",
                'tasks': tasks
            }
        else:
            # After 14 entries: Predict mood using weighted moving average
            weights = np.linspace(1, 0.5, min(len(mood_scores), 14))  # Recent entries have higher weight
            weighted_scores = np.array([score[0] * score[1] for score, _ in mood_scores[:14]]) * weights
            avg_score = np.mean(weighted_scores) / np.mean(weights)
            
            # Behavioral factors (e.g., day of week)
            weekdays = [entry[2].weekday() < 5 for entry in mood_scores[:14]]
            weekday_avg = np.mean([score[0] for score, is_weekday in zip(mood_scores[:14], weekdays) if is_weekday]) if any(weekdays) else None
            weekend_avg = np.mean([score[0] for score, is_weekday in zip(mood_scores[:14], weekdays) if not is_weekday]) if any(not w for w in weekdays) else None
            
            # Predict mood
            predicted_score = avg_score
            if weekday_avg and weekend_avg and weekday_avg < weekend_avg - 0.5:
                predicted_score -= 0.5  # Adjust for lower weekday moods
            predicted_mood = min(mood_map.keys(), key=lambda k: abs(mood_map[k] - predicted_score))
            
            tasks = fetch_mood_tasks(conn, predicted_mood)
            stability = min(max(round(np.mean([entry['intensity'] for entry in entries[:14]]) * 10), 0), 100)
            message = f"Predicted mood: {predicted_mood.lower()} based on {len(entries)} entries. { 'Consider booking a session if mood dips persist.' if predicted_mood in ['Sad', 'Angry'] else 'Keep it up!' }"
            
            return {
                'success': True,
                'mood': predicted_mood,
                'stability': stability,
                'message': message,
                'tasks': tasks
            }
    except Exception as e:
        logging.error(f"Error predicting mood for user_id {user_id}: {e}")
        return {
            'success': False,
            'mood': 'Neutral',
            'stability': 50,
            'message': f'Unable to predict mood due to an error: {str(e)}',
            'tasks': []
        }
    finally:
        if 'conn' in locals():
            conn.close()

if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            logging.error("No user_id provided as command-line argument")
            print(json.dumps({
                'success': False,
                'mood': 'Neutral',
                'stability': 50,
                'message': 'Error: Please provide a user_id as a command-line argument',
                'tasks': []
            }))
            sys.exit(1)
        user_id = int(sys.argv[1])
        logging.info(f"Starting mood prediction for user_id {user_id}")
        prediction = predict_mood(user_id)
        print(json.dumps(prediction))
    except ValueError as e:
        logging.error(f"Invalid user_id format: {e}")
        print(json.dumps({
            'success': False,
            'mood': 'Neutral',
            'stability': 50,
            'message': f'Error: user_id must be an integer: {str(e)}',
            'tasks': []
        }))
        sys.exit(1)
    except Exception as e:
        logging.error(f"Unexpected error: {e}")
        print(json.dumps({
            'success': False,
            'mood': 'Neutral',
            'stability': 50,
            'message': f'Error: An unexpected error occurred: {str(e)}',
            'tasks': []
        }))
        sys.exit(1)
